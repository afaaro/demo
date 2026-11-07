<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use Module\StoreFeeder\Services\Channels\NotSupportedException;
use RuntimeException;
use System\Library\Database;

final class ConversationSyncService
{
    private const DEFAULT_MAX_PAGES = 20;
    private const PAGE_SIZE         = 50;

    public function __construct(
        private Database               $db,
        private ChannelRepository      $channels,
        private ChannelClientFactory   $factory,
        private ConversationRepository $conversations,
    ) {}

    /**
     * Sync conversation headers. Thread bodies are loaded on open / Refresh
     * so a single web request does not hammer the channel API.
     *
     * @param int $maxThreadFetches  Optional: prefetch up to N thread bodies (0 = headers only)
     * @param int $maxPages          Cap list pages (web should use a small number)
     * @return array{created: int, updated: int, messages: int, pages: int, truncated: bool}
     */
    public function syncChannel(
        Channel $channel,
        string $type = 'FROM_MEMBERS',
        int $maxThreadFetches = 0,
        int $maxPages = self::DEFAULT_MAX_PAGES,
    ): array {
        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        if (!$this->channelTenantAllowed($channel)) {
            throw new RuntimeException(
                "Conversation sync blocked: channel '{$channel->name}' belongs to another tenant. "
                . 'Switch to that tenant (Working in) and try again.'
            );
        }

        if ($this->factory->isCourier($channel)) {
            return [
                'created'   => 0,
                'updated'   => 0,
                'messages'  => 0,
                'pages'     => 0,
                'truncated' => false,
            ];
        }

        if (!ChannelSettings::bool($channel, 'download_channel_messages', true)) {
            return [
                'created'   => 0,
                'updated'   => 0,
                'messages'  => 0,
                'pages'     => 0,
                'truncated' => false,
            ];
        }

        // Repair rows saved under the wrong tenant (unique key is channel+external).
        $this->conversations->rebindChannelTenant((int) $channel->id, (int) $channel->tenantId);

        $maxPages = max(1, min(self::DEFAULT_MAX_PAGES, $maxPages));
        $client   = $this->factory->make($channel);

        $created        = 0;
        $updated        = 0;
        $messagesCached = 0;
        $threadsFetched = 0;
        $offset         = 0;
        $pages          = 0;
        $truncated      = false;

        do {
            try {
                $result = $client->fetchConversations(self::PAGE_SIZE, $offset, $type);
            } catch (NotSupportedException) {
                return [
                    'created'   => 0,
                    'updated'   => 0,
                    'messages'  => 0,
                    'pages'     => 0,
                    'truncated' => false,
                ];
            }

            $conversations = is_array($result['conversations'] ?? null)
                ? $result['conversations']
                : [];

            foreach ($conversations as $conv) {
                if (!is_array($conv)) {
                    continue;
                }

                $status = $this->conversations->upsert((int) $channel->id, $conv);
                if ($status === 'created') {
                    $created++;
                }
                if ($status === 'updated') {
                    $updated++;
                }

                if ($maxThreadFetches <= 0 || $threadsFetched >= $maxThreadFetches) {
                    continue;
                }

                $convId   = (string) ($conv['conversationId']   ?? '');
                $convType = (string) ($conv['conversationType'] ?? $type);

                if ($convId === '') {
                    continue;
                }

                $convType = $this->normalizeConversationType($convType, $type);

                try {
                    if (!method_exists($client, 'fetchConversation')) {
                        continue;
                    }
                    $thread = $client->fetchConversation($convId, $convType);
                    $msgs   = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];
                    $threadsFetched++;

                    foreach ($msgs as $m) {
                        if (!is_array($m)) {
                            continue;
                        }
                        $st = $this->conversations->upsertMessage((int) $channel->id, $convId, $m);
                        if ($st !== 'skipped') {
                            $messagesCached++;
                        }
                    }
                } catch (\Throwable $e) {
                    error_log("Conversation {$convId} thread fetch failed: " . $e->getMessage());
                }
            }

            $pages++;

            $next = $result['next'] ?? null;
            if (is_string($next) && $next !== '') {
                $offset = $next;
            } elseif (is_int($next) || (is_string($next) && ctype_digit($next))) {
                $offset = (int) $next;
            } else {
                // Legacy eBay-style: bump numeric offset when a full page returned.
                if (count($conversations) < self::PAGE_SIZE) {
                    break;
                }
                $offset = (is_int($offset) ? $offset : 0) + self::PAGE_SIZE;
            }

            if ($pages >= $maxPages) {
                $truncated = true;
                break;
            }

            if ($next === null && count($conversations) < self::PAGE_SIZE) {
                break;
            }
            if ($next === null && !is_int($offset) && !ctype_digit((string) $offset)) {
                break;
            }
        } while (true);

        return [
            'created'   => $created,
            'updated'   => $updated,
            'messages'  => $messagesCached,
            'pages'     => $pages,
            'truncated' => $truncated,
        ];
    }

    /**
     * Refresh a single conversation's thread.
     *
     * @return array{messages: int}
     */
    public function syncThread(Channel $channel, string $conversationId, string $conversationType = 'FROM_MEMBERS'): array
    {
        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        if (!$this->channelTenantAllowed($channel)) {
            throw new RuntimeException(
                "Conversation sync blocked: channel '{$channel->name}' belongs to another tenant. "
                . 'Switch to that tenant (Working in) and try again.'
            );
        }

        $this->conversations->rebindChannelTenant((int) $channel->id, (int) $channel->tenantId);

        $conversationType = $this->normalizeConversationType($conversationType, 'FROM_MEMBERS');

        $client = $this->factory->make($channel);
        if (!method_exists($client, 'fetchConversation')) {
            throw new RuntimeException('This channel does not support conversation threads.');
        }
        $thread = $client->fetchConversation($conversationId, $conversationType);
        $msgs   = is_array($thread['messages'] ?? null) ? $thread['messages'] : [];

        $count = 0;
        foreach ($msgs as $m) {
            if (!is_array($m)) {
                continue;
            }
            $st = $this->conversations->upsertMessage((int) $channel->id, $conversationId, $m);
            if ($st !== 'skipped') {
                $count++;
            }
        }

        return ['messages' => $count];
    }

    private function normalizeConversationType(string $convType, string $fallback): string
    {
        if (in_array($convType, ['FROM_EBAY', 'FROM_MEMBERS', 'AMAZON_ORDER', 'BNQ_THREAD'], true)) {
            return $convType;
        }
        return $fallback !== '' ? $fallback : 'FROM_MEMBERS';
    }

    private function channelTenantAllowed(Channel $channel): bool
    {
        $tid = (int) $channel->tenantId;
        if ($tid <= 0) {
            return false;
        }
        if (TenantContext::get()->has() && TenantContext::get()->id() !== $tid) {
            return false;
        }
        return true;
    }
}
