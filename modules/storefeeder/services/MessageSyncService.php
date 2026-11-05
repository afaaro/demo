<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use RuntimeException;
use System\Library\Database;

final class MessageSyncService
{
    use TenantScoped;

    /** eBay's GetMyMessages returns up to 100 per page. */
    private const PAGE_SIZE = 100;

    public function __construct(
        private Database             $db,
        private ChannelRepository    $channels,
        private ChannelClientFactory $factory,
    ) {}

    /**
     * Sync message headers for one channel + folder.
     *
     * Bodies are intentionally NOT fetched here — eBay's GetMyMessages
     * requires MessageIDs when using DetailLevel=ReturnMessages, and
     * fetching bodies for every message in a folder is wasteful. Bodies
     * are fetched on demand via fetchBody().
     *
     * @return array{created: int, updated: int, pages: int, truncated: bool}
     */
    public function syncChannel(Channel $channel, int $folderId = 0, int $maxPages = 10): array
    {
        if (!$this->channelTenantAllowed($channel)) {
            return ['created' => 0, 'updated' => 0, 'pages' => 0, 'truncated' => false];
        }

        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        if (!ChannelSettings::bool($channel, 'download_channel_messages', true)) {
            return ['created' => 0, 'updated' => 0, 'pages' => 0, 'truncated' => false];
        }

        if (!in_array($folderId, [0, 1], true)) {
            throw new RuntimeException("Invalid folder id: {$folderId}.");
        }

        $client    = $this->factory->make($channel);
        $created   = 0;
        $updated   = 0;
        $pages     = 0;
        $truncated = false;

        for ($page = 1; $page <= $maxPages; $page++) {
            $result = $client->fetchMyMessages($folderId, 'ReturnHeaders', [], $page, self::PAGE_SIZE);

            $messages = $result['messages'] ?? [];
            if (!is_array($messages) || $messages === []) {
                break;
            }

            $pages++;

            foreach ($messages as $m) {
                [$status] = $this->upsertMessage(
                    (int) $channel->id,
                    (int) $channel->tenantId,
                    $folderId,
                    $m
                );
                if ($status === 'created') $created++;
                elseif ($status === 'updated') $updated++;
            }

            if (count($messages) < self::PAGE_SIZE) {
                break;
            }

            if ($page === $maxPages) {
                $truncated = true;
            }
        }

        return [
            'created'   => $created,
            'updated'   => $updated,
            'pages'     => $pages,
            'truncated' => $truncated,
        ];
    }

    /**
     * Fetch and store a single message's body (and any fields only
     * available with ReturnMessages).
     *
     * @return bool  true if the body was fetched and stored
     */
    public function fetchBody(Channel $channel, int $folderId, string $externalId): bool
    {
        if (!$this->channelTenantAllowed($channel)) {
            return false;
        }

        if (!$channel->isConnected()) {
            throw new RuntimeException("Channel '{$channel->name}' is not connected.");
        }

        $externalId = trim($externalId);
        if ($externalId === '') {
            throw new RuntimeException('Message ID is required.');
        }

        $client = $this->factory->make($channel);

        $result = $client->fetchMyMessages(
            $folderId,
            'ReturnMessages',
            [$externalId],
            1,
            1,
        );

        $messages = $result['messages'] ?? [];
        if (!is_array($messages) || $messages === []) {
            return false;
        }

        // First (and only) result.
        $m = $messages[0] ?? null;
        if (!is_array($m)) {
            return false;
        }

        // Match by message_id (eBay may return a slightly different case).
        $returnedId = (string) ($m['message_id'] ?? '');
        if ($returnedId === '') {
            return false;
        }

        $this->updateBody((int) $channel->id, (int) $channel->tenantId, $folderId, $m);

        return true;
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * @param array<string, mixed> $m
     * @return array{0: string, 1: int}
     */
    private function upsertMessage(int $channelId, int $tenantId, int $folderId, array $m): array
    {
        $externalId = trim((string) ($m['message_id'] ?? ''));
        if ($externalId === '' || $tenantId <= 0) {
            return ['skipped', 0];
        }

        $existing = $this->db->first('sf_messages', [
            'tenant_id'   => $tenantId,
            'channel_id'  => $channelId,
            'external_id' => $externalId,
        ]);

        $data = [
            'tenant_id'     => $tenantId,
            'channel_id'    => $channelId,
            'external_id'   => $externalId,
            'folder'        => $folderId,
            'sender'        => mb_substr((string) ($m['sender']         ?? ''), 0, 255),
            'recipient'     => mb_substr((string) ($m['recipient']      ?? ''), 0, 255),
            'subject'       => mb_substr((string) ($m['subject']        ?? ''), 0, 500),
            'content'       => (string) ($m['content'] ?? ''),
            'item_id'       => mb_substr((string) ($m['item_id']        ?? ''), 0, 64),
            'item_title'    => mb_substr((string) ($m['item_title']     ?? ''), 0, 500),
            'message_type'  => mb_substr((string) ($m['message_type']   ?? ''), 0, 64),
            'question_type' => mb_substr((string) ($m['question_type']  ?? ''), 0, 64),
            'is_read'       => !empty($m['read'])    ? 1 : 0,
            'is_replied'    => !empty($m['replied']) ? 1 : 0,
            'receive_date'  => $this->parseDate($m['receive_date'] ?? null),
            'synced_at'     => date('Y-m-d H:i:s'),
        ];

        if ($existing === []) {
            if (TenantContext::get()->has()) {
                $id = (int) $this->db->insert('sf_messages', $this->withTenant($data));
            } else {
                $id = (int) $this->db->insert('sf_messages', $data);
            }
            return ['created', $id];
        }

        if ((int) ($existing['tenant_id'] ?? 0) !== $tenantId) {
            return ['skipped', 0];
        }

        // On update, don't overwrite a stored body with the empty
        // content from a ReturnHeaders fetch.
        if ($data['content'] === '') {
            unset($data['content']);
        }

        $id = (int) $existing['id'];
        $this->db->update('sf_messages', $data, [
            'id'        => $id,
            'tenant_id' => $tenantId,
        ]);
        return ['updated', $id];
    }

    /**
     * Update the body of an existing message. Called by fetchBody().
     *
     * @param array<string, mixed> $m
     */
    private function updateBody(int $channelId, int $tenantId, int $folderId, array $m): void
    {
        $externalId = trim((string) ($m['message_id'] ?? ''));
        if ($externalId === '' || $tenantId <= 0) {
            return;
        }

        $existing = $this->db->first('sf_messages', [
            'tenant_id'   => $tenantId,
            'channel_id'  => $channelId,
            'external_id' => $externalId,
        ]);

        if ($existing === []) {
            // Not stored yet — insert with everything we got.
            $this->upsertMessage($channelId, $tenantId, $folderId, $m);
            return;
        }

        if ((int) ($existing['tenant_id'] ?? 0) !== $tenantId) {
            return;
        }

        $update = [
            'content'       => (string) ($m['content']       ?? ''),
            'sender'        => mb_substr((string) ($m['sender']        ?? ($existing['sender'] ?? '')), 0, 255),
            'recipient'     => mb_substr((string) ($m['recipient']     ?? ($existing['recipient'] ?? '')), 0, 255),
            'subject'       => mb_substr((string) ($m['subject']       ?? ($existing['subject'] ?? '')), 0, 500),
            'item_id'       => mb_substr((string) ($m['item_id']       ?? ($existing['item_id'] ?? '')), 0, 64),
            'item_title'    => mb_substr((string) ($m['item_title']    ?? ($existing['item_title'] ?? '')), 0, 500),
            'message_type'  => mb_substr((string) ($m['message_type']  ?? ($existing['message_type'] ?? '')), 0, 64),
            'question_type' => mb_substr((string) ($m['question_type'] ?? ($existing['question_type'] ?? '')), 0, 64),
            'is_read'       => !empty($m['read'])    ? 1 : (int) ($existing['is_read'] ?? 0),
            'is_replied'    => !empty($m['replied']) ? 1 : (int) ($existing['is_replied'] ?? 0),
            'synced_at'     => date('Y-m-d H:i:s'),
        ];

        $this->db->update('sf_messages', $update, [
            'id'        => (int) $existing['id'],
            'tenant_id' => $tenantId,
        ]);
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

    private function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $ts = strtotime((string) $raw);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}