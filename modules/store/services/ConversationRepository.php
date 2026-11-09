<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

final class ConversationRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $channelId, string $externalId): ?array
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        // Unique key is (channel_id, external_id) — look up without tenant so
        // orphaned rows (wrong tenant_id) can be corrected on upsert.
        $row = $this->db->query(
            "SELECT * FROM `#__store_conversations`
              WHERE `channel_id` = ? AND `external_id` = ?
              LIMIT 1",
            [$channelId, $externalId]
        )->row;

        return $row !== [] ? $row : null;
    }

    /**
     * Point all conversation rows for a channel at the channel's real tenant.
     * Fixes silent unique-key failures when older rows were saved under the
     * wrong tenant_id.
     */
    public function rebindChannelTenant(int $channelId, int $tenantId): int
    {
        $channelId = max(0, $channelId);
        $tenantId  = max(1, $tenantId);
        if ($channelId <= 0) {
            return 0;
        }

        $headers = (int) $this->db->query(
            "UPDATE `#__store_conversations`
                SET `tenant_id` = ?
              WHERE `channel_id` = ? AND `tenant_id` <> ?",
            [$tenantId, $channelId, $tenantId]
        )->affected;

        $messages = (int) $this->db->query(
            "UPDATE `#__store_conversation_messages`
                SET `tenant_id` = ?
              WHERE `channel_id` = ? AND `tenant_id` <> ?",
            [$tenantId, $channelId, $tenantId]
        )->affected;

        return $headers + $messages;
    }

    /**
     * Resolve the tenant used for reads. Prefer the channel's own tenant so
     * Mail ↔ Conversations tab switches cannot hide rows when ambient
     * TenantContext briefly disagrees with the channel.
     */
    private function resolveTenantId(?int $tenantId = null): int
    {
        if ($tenantId !== null && $tenantId > 0) {
            return $tenantId;
        }
        if (TenantContext::get()->has()) {
            return $this->tenantId();
        }
        return 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paginate(int $channelId, int $limit = 25, int $offset = 0, ?int $tenantId = null): array
    {
        $tid = $this->resolveTenantId($tenantId);
        if ($tid <= 0) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__store_conversations`
              WHERE `channel_id` = ? AND `tenant_id` = ?
              ORDER BY `latest_date` DESC, `id` DESC
              LIMIT ? OFFSET ?",
            [$channelId, $tid, $limit, $offset]
        )->rows;
    }

    public function count(int $channelId, ?int $tenantId = null): int
    {
        $tid = $this->resolveTenantId($tenantId);
        if ($tid <= 0) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_conversations` WHERE `channel_id` = ? AND `tenant_id` = ?",
            [$channelId, $tid]
        )->value;
    }

    public function countUnread(int $channelId, ?int $tenantId = null): int
    {
        $tid = $this->resolveTenantId($tenantId);
        if ($tid <= 0) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_conversations`
              WHERE `channel_id` = ? AND `tenant_id` = ? AND `unread_count` > 0",
            [$channelId, $tid]
        )->value;
    }

    public function lastSyncedAt(int $channelId, ?int $tenantId = null): ?string
    {
        $tid = $this->resolveTenantId($tenantId);
        if ($tid <= 0) {
            return null;
        }
        $value = $this->db->query(
            "SELECT MAX(`synced_at`) FROM `#__store_conversations`
              WHERE `channel_id` = ? AND `tenant_id` = ?",
            [$channelId, $tid]
        )->value;
        return $value ? (string) $value : null;
    }

    /**
     * Upsert one conversation.
     *
     * @param array<string, mixed> $conv
     */
    public function upsert(int $channelId, array $conv): string
    {
        if (!TenantContext::get()->has()) {
            return 'skipped';
        }
        $externalId = (string) ($conv['conversationId'] ?? '');
        if ($externalId === '') {
            return 'skipped';
        }

        $latest = is_array($conv['latestMessage'] ?? null) ? $conv['latestMessage'] : [];

        $incomingBody   = mb_substr((string) ($latest['messageBody'] ?? ''), 0, 1000);
        $incomingSender = (string) ($latest['senderUsername'] ?? '');
        $incomingMsgId  = (string) ($latest['messageId'] ?? '');
        $incomingDate   = $this->parseDate((string) ($latest['createdDate'] ?? ''));

        $data = [
            'channel_id'        => $channelId,
            'external_id'       => $externalId,
            'conversation_type' => (string) ($conv['conversationType']   ?? 'FROM_MEMBERS'),
            'status'            => (string) ($conv['conversationStatus'] ?? 'ACTIVE'),
            'reference_id'      => (string) ($conv['referenceId']        ?? ''),
            'reference_type'    => (string) ($conv['referenceType']      ?? ''),
            'unread_count'      => (int)    ($conv['unreadCount']        ?? 0),
            'latest_message_id' => $incomingMsgId,
            'latest_body'       => $incomingBody,
            'latest_sender'     => $incomingSender,
            'latest_date'       => $incomingDate,
            'synced_at'         => date('Y-m-d H:i:s'),
        ];

        $existing = $this->find($channelId, $externalId);
        // Prefer the existing row's tenant, then ambient context — avoids writing
        // headers under the wrong "Working in" tenant during tab switches.
        if ($existing !== null && (int) ($existing['tenant_id'] ?? 0) > 0) {
            $data['tenant_id'] = (int) $existing['tenant_id'];
        } else {
            $data = $this->withTenant($data);
        }

        if ($existing === null) {
            try {
                $this->db->insert('store_conversations', $data);
                return 'created';
            } catch (\Throwable $e) {
                // Concurrent insert or leftover unique row — fall through to update.
                $existing = $this->find($channelId, $externalId);
                if ($existing === null) {
                    throw $e;
                }
            }
        }

        if ((int) ($existing['tenant_id'] ?? 0) > 0) {
            $data['tenant_id'] = (int) $existing['tenant_id'];
        }

        // Never blank out a good list preview when eBay omits latestMessage fields.
        if ($incomingBody === '' && trim((string) ($existing['latest_body'] ?? '')) !== '') {
            unset($data['latest_body']);
        }
        if ($incomingSender === '' && trim((string) ($existing['latest_sender'] ?? '')) !== '') {
            unset($data['latest_sender']);
        }
        if ($incomingMsgId === '' && trim((string) ($existing['latest_message_id'] ?? '')) !== '') {
            unset($data['latest_message_id']);
        }
        if ($incomingDate === null && !empty($existing['latest_date'])) {
            unset($data['latest_date']);
        }
        // Keep a richer local type (e.g. after thread open) if payload type is empty.
        if ($data['conversation_type'] === '' && trim((string) ($existing['conversation_type'] ?? '')) !== '') {
            unset($data['conversation_type']);
        }

        $this->db->update('store_conversations', $data, ['id' => (int) $existing['id']]);
        return 'updated';
    }

    public function setUnread(int $channelId, string $externalId, int $unread): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $this->db->query(
            "UPDATE `#__store_conversations`
                SET `unread_count` = ?
              WHERE `channel_id` = ? AND `external_id` = ? AND `tenant_id` = ?",
            [$unread, $channelId, $externalId, $tid]
        );
    }

    /**
     * Mark a conversation and its cached messages as read locally.
     */
    public function markReadLocally(int $channelId, string $externalId): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $this->setUnread($channelId, $externalId, 0);

        $this->db->query(
            "UPDATE `#__store_conversation_messages`
                SET `read_status` = 1
              WHERE `channel_id` = ? AND `conversation_id` = ? AND `tenant_id` = ?",
            [$channelId, $externalId, $tid]
        );
    }

    private function parseDate(string $iso): ?string
    {
        if ($iso === '') return null;
        $ts = strtotime($iso);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /**
     * Return all cached messages for a conversation, oldest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function messagesFor(int $channelId, string $conversationId, ?int $tenantId = null): array
    {
        $tid = $this->resolveTenantId($tenantId);
        if ($tid <= 0) {
            // Fall back: thread id is unique per channel — still show cached rows
            // if ambient tenant context is missing/mismatched.
            return $this->db->query(
                "SELECT * FROM `#__store_conversation_messages`
                  WHERE `channel_id` = ? AND `conversation_id` = ?
                  ORDER BY `created_date` ASC, `id` ASC",
                [$channelId, $conversationId]
            )->rows;
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__store_conversation_messages`
              WHERE `channel_id` = ? AND `conversation_id` = ? AND `tenant_id` = ?
              ORDER BY `created_date` ASC, `id` ASC",
            [$channelId, $conversationId, $tid]
        )->rows;
        if ($rows !== []) {
            return $rows;
        }
        // Tenant drift: show any cached messages for this thread rather than
        // an empty pane after Mail ↔ Conversations navigation.
        return $this->db->query(
            "SELECT * FROM `#__store_conversation_messages`
              WHERE `channel_id` = ? AND `conversation_id` = ?
              ORDER BY `created_date` ASC, `id` ASC",
            [$channelId, $conversationId]
        )->rows;
    }

    /**
     * Upsert one message from a conversation thread.
     *
     * @param array<string, mixed> $msg  eBay's message object.
     */
    public function upsertMessage(int $channelId, string $conversationId, array $msg): string
    {
        if (!TenantContext::get()->has()) {
            return 'skipped';
        }
        $externalId = (string) ($msg['messageId'] ?? '');
        if ($externalId === '') {
            return 'skipped';
        }

        $tid = $this->tenantId();
        $createdIso = (string) ($msg['createdDate'] ?? '');

        $incomingBody = (string) ($msg['messageBody'] ?? '');

        $data = [
            'channel_id'      => $channelId,
            'conversation_id' => $conversationId,
            'external_id'     => $externalId,
            'sender'          => (string) ($msg['senderUsername']    ?? ''),
            'recipient'       => (string) ($msg['recipientUsername'] ?? ''),
            'body'            => $incomingBody,
            'read_status'     => array_key_exists('readStatus', $msg)
                ? (!empty($msg['readStatus']) ? 1 : 0)
                : 0,
            'created_date'    => $this->parseDate($createdIso),
            'synced_at'       => date('Y-m-d H:i:s'),
        ];

        $data = $this->withTenant($data);

        $existing = $this->db->query(
            "SELECT `id`, `body`, `sender`, `recipient`, `created_date` FROM `#__store_conversation_messages`
              WHERE `channel_id` = ? AND `external_id` = ?
              LIMIT 1",
            [$channelId, $externalId]
        )->row;

        if ($existing === []) {
            try {
                $this->db->insert('store_conversation_messages', $data);
                return 'created';
            } catch (\Throwable $e) {
                $existing = $this->db->query(
                    "SELECT `id`, `body`, `sender`, `recipient`, `created_date` FROM `#__store_conversation_messages`
                      WHERE `channel_id` = ? AND `external_id` = ?
                      LIMIT 1",
                    [$channelId, $externalId]
                )->row;
                if ($existing === []) {
                    throw $e;
                }
            }
        }

        // Keep a previously fetched body if this payload has none (headers / sparse sync).
        if ($incomingBody === '' && trim((string) ($existing['body'] ?? '')) !== '') {
            unset($data['body']);
        }
        if ($data['sender'] === '' && trim((string) ($existing['sender'] ?? '')) !== '') {
            unset($data['sender']);
        }
        if ($data['recipient'] === '' && trim((string) ($existing['recipient'] ?? '')) !== '') {
            unset($data['recipient']);
        }
        if ($data['created_date'] === null && !empty($existing['created_date'])) {
            unset($data['created_date']);
        }

        $this->db->update(
            'store_conversation_messages',
            $data,
            ['id' => (int) $existing['id']]
        );
        return 'updated';
    }

    public function messageCount(int $channelId, string $conversationId): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_conversation_messages`
              WHERE `channel_id` = ? AND `conversation_id` = ? AND `tenant_id` = ?",
            [$channelId, $conversationId, $this->tenantId()]
        )->value;
    }
}
