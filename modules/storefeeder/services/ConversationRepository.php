<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

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
        $row = $this->db->query(
            "SELECT * FROM `#__sf_conversations`
              WHERE `channel_id` = ? AND `external_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$channelId, $externalId, $this->tenantId()]
        )->row;

        return $row !== [] ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paginate(int $channelId, int $limit = 25, int $offset = 0): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__sf_conversations`
              WHERE `channel_id` = ? AND `tenant_id` = ?
              ORDER BY `latest_date` DESC, `id` DESC
              LIMIT ? OFFSET ?",
            [$channelId, $this->tenantId(), $limit, $offset]
        )->rows;
    }

    public function count(int $channelId): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_conversations` WHERE `channel_id` = ? AND `tenant_id` = ?",
            [$channelId, $this->tenantId()]
        )->value;
    }

    public function countUnread(int $channelId): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_conversations`
              WHERE `channel_id` = ? AND `tenant_id` = ? AND `unread_count` > 0",
            [$channelId, $this->tenantId()]
        )->value;
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

        $data = [
            'channel_id'        => $channelId,
            'external_id'       => $externalId,
            'conversation_type' => (string) ($conv['conversationType']   ?? 'FROM_MEMBERS'),
            'status'            => (string) ($conv['conversationStatus'] ?? 'ACTIVE'),
            'reference_id'      => (string) ($conv['referenceId']        ?? ''),
            'reference_type'    => (string) ($conv['referenceType']      ?? ''),
            'unread_count'      => (int)    ($conv['unreadCount']        ?? 0),
            'latest_message_id' => (string) ($latest['messageId']        ?? ''),
            'latest_body'       => mb_substr((string) ($latest['messageBody'] ?? ''), 0, 1000),
            'latest_sender'     => (string) ($latest['senderUsername']   ?? ''),
            'latest_date'       => $this->parseDate((string) ($latest['createdDate'] ?? '')),
            'synced_at'         => date('Y-m-d H:i:s'),
        ];

        $existing = $this->find($channelId, $externalId);

        if ($existing === null) {
            $this->db->insert('sf_conversations', $this->withTenant($data));
            return 'created';
        }

        $this->db->update('sf_conversations', $data, $this->withTenant(['id' => (int) $existing['id']]));
        return 'updated';
    }

    public function setUnread(int $channelId, string $externalId, int $unread): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $this->db->query(
            "UPDATE `#__sf_conversations`
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
            "UPDATE `#__sf_conversation_messages`
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
    public function messagesFor(int $channelId, string $conversationId): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__sf_conversation_messages`
              WHERE `channel_id` = ? AND `conversation_id` = ? AND `tenant_id` = ?
              ORDER BY `created_date` ASC, `id` ASC",
            [$channelId, $conversationId, $this->tenantId()]
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

        $data = [
            'channel_id'      => $channelId,
            'conversation_id' => $conversationId,
            'external_id'     => $externalId,
            'sender'          => (string) ($msg['senderUsername']    ?? ''),
            'recipient'       => (string) ($msg['recipientUsername'] ?? ''),
            'body'            => (string) ($msg['messageBody']       ?? ''),
            'read_status'     => array_key_exists('readStatus', $msg)
                ? (!empty($msg['readStatus']) ? 1 : 0)
                : 0,
            'created_date'    => $this->parseDate($createdIso),
            'synced_at'       => date('Y-m-d H:i:s'),
        ];

        $existing = $this->db->query(
            "SELECT `id` FROM `#__sf_conversation_messages`
              WHERE `channel_id` = ? AND `external_id` = ? AND `tenant_id` = ?
              LIMIT 1",
            [$channelId, $externalId, $tid]
        )->row;

        if ($existing === []) {
            $this->db->insert('sf_conversation_messages', $this->withTenant($data));
            return 'created';
        }

        $this->db->update(
            'sf_conversation_messages',
            $data,
            ['id' => (int) $existing['id'], 'tenant_id' => $tid]
        );
        return 'updated';
    }

    public function messageCount(int $channelId, string $conversationId): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_conversation_messages`
              WHERE `channel_id` = ? AND `conversation_id` = ? AND `tenant_id` = ?",
            [$channelId, $conversationId, $this->tenantId()]
        )->value;
    }
}
