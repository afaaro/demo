<?php
declare(strict_types=1);

namespace Module\Store\Services;

use Module\Store\Services\Channels\NotSupportedException;
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
        $seenOffsets    = [];

        do {
            $offsetKey = is_scalar($offset) ? (string) $offset : '';
            if ($offsetKey !== '' && isset($seenOffsets[$offsetKey])) {
                // Guard against broken next tokens that replay the same page.
                break;
            }
            if ($offsetKey !== '') {
                $seenOffsets[$offsetKey] = true;
            }

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
            if ($next === null || $next === '') {
                break;
            }

            // Numeric offsets (eBay/Amazon) or opaque page tokens (BNQ/Mirakl).
            // Never treat an http(s) URI as an offset — that previously cast to 0.
            if (is_int($next)) {
                $offset = $next;
            } elseif (is_string($next) && $next !== '') {
                if (str_starts_with($next, 'http://') || str_starts_with($next, 'https://')) {
                    $parsed = $this->offsetFromNextUri($next);
                    if ($parsed === null) {
                        break;
                    }
                    $offset = $parsed;
                } else {
                    $offset = $next;
                }
            } else {
                break;
            }

            if ($pages >= $maxPages) {
                $truncated = true;
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

        $count  = 0;
        $latest = null;
        foreach ($msgs as $m) {
            if (!is_array($m)) {
                continue;
            }
            $st = $this->conversations->upsertMessage((int) $channel->id, $conversationId, $m);
            if ($st !== 'skipped') {
                $count++;
            }
            $latest = $this->newerMessage($latest, $m);
        }

        // Keep the conversation list preview/date in sync when a thread is refreshed.
        if ($latest !== null && trim((string) ($latest['messageId'] ?? '')) !== '') {
            $existing = $this->conversations->find((int) $channel->id, $conversationId);
            if ($existing !== null) {
                $this->conversations->upsert((int) $channel->id, [
                    'conversationId'     => $conversationId,
                    'conversationType'   => (string) ($existing['conversation_type'] ?? $conversationType),
                    'conversationStatus' => (string) ($existing['status'] ?? 'ACTIVE'),
                    'referenceId'        => (string) ($existing['reference_id'] ?? ''),
                    'referenceType'      => (string) ($existing['reference_type'] ?? ''),
                    'unreadCount'        => (int) ($existing['unread_count'] ?? 0),
                    'latestMessage'      => $latest,
                ]);
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

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function newerMessage(?array $current, array $candidate): array
    {
        if ($current === null) {
            return $candidate;
        }
        $a = strtotime((string) ($current['createdDate'] ?? '')) ?: 0;
        $b = strtotime((string) ($candidate['createdDate'] ?? '')) ?: 0;
        return $b >= $a ? $candidate : $current;
    }

    private function offsetFromNextUri(string $uri): ?int
    {
        $query = parse_url($uri, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }
        parse_str($query, $params);
        if (!isset($params['offset']) || !is_numeric($params['offset'])) {
            return null;
        }
        return (int) $params['offset'];
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
