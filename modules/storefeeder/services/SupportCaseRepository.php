<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use System\Library\Database;

final class SupportCaseRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function paginate(
        int $channelId,
        int $limit = 25,
        int $offset = 0,
        string $status = '',
        string $kind = '',
    ): array {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $sql = "SELECT * FROM `#__sf_support_cases` WHERE `channel_id` = ? AND `tenant_id` = ?";
        $params = [$channelId, $tid];
        if ($status !== '') {
            $sql .= " AND `status` = ?";
            $params[] = $status;
        }
        if ($kind !== '') {
            $sql .= " AND `kind` = ?";
            $params[] = $kind;
        }
        $sql .= " ORDER BY COALESCE(`last_modified_date`, `creation_date`) DESC, `id` DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->db->query($sql, $params)->rows;
    }

    public function count(int $channelId, string $status = '', string $kind = ''): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        $tid = $this->tenantId();
        $sql = "SELECT COUNT(*) FROM `#__sf_support_cases` WHERE `channel_id` = ? AND `tenant_id` = ?";
        $params = [$channelId, $tid];
        if ($status !== '') {
            $sql .= " AND `status` = ?";
            $params[] = $status;
        }
        if ($kind !== '') {
            $sql .= " AND `kind` = ?";
            $params[] = $kind;
        }
        return (int) $this->db->query($sql, $params)->value;
    }

    public function countOpen(int $channelId): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_support_cases`
              WHERE `channel_id` = ?
                AND `tenant_id` = ?
                AND UPPER(`status`) NOT IN (
                    'CLOSED','CS_CLOSED','OTHER','CLOSED_WITH_ESCALATION',
                    'APPROVED','COMPLETED','REFUNDED','DENIED','CANCELLED','CANCELED',
                    'POSTED','REIMBURSED','RECEIVED'
                )",
            [$channelId, $this->tenantId()]
        )->value;
    }

    /**
     * Open cases/inquiries with a respond-by date within the next $withinHours (incl. overdue).
     *
     * @return array<int, array<string, mixed>>
     */
    public function respondByAlerts(int $channelId = 0, int $withinHours = 48, int $limit = 100): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $sql = "SELECT * FROM `#__sf_support_cases`
                 WHERE `tenant_id` = ?
                   AND `seller_response_due` IS NOT NULL
                   AND UPPER(`status`) NOT IN (
                        'CLOSED','CS_CLOSED','OTHER','CLOSED_WITH_ESCALATION',
                        'APPROVED','COMPLETED','REFUNDED','DENIED','CANCELLED','CANCELED',
                        'POSTED','REIMBURSED','RECEIVED'
                   )
                   AND `seller_response_due` <= DATE_ADD(NOW(), INTERVAL ? HOUR)";
        $params = [$tid, max(1, $withinHours)];
        if ($channelId > 0) {
            $sql .= " AND `channel_id` = ?";
            $params[] = $channelId;
        }
        $sql .= " ORDER BY `seller_response_due` ASC LIMIT ?";
        $params[] = max(1, min(500, $limit));

        return $this->db->query($sql, $params)->rows;
    }

    public function countRespondByAlerts(int $channelId = 0, int $withinHours = 48): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        $tid = $this->tenantId();
        $sql = "SELECT COUNT(*) FROM `#__sf_support_cases`
                 WHERE `tenant_id` = ?
                   AND `seller_response_due` IS NOT NULL
                   AND UPPER(`status`) NOT IN (
                        'CLOSED','CS_CLOSED','OTHER','CLOSED_WITH_ESCALATION',
                        'APPROVED','COMPLETED','REFUNDED','DENIED','CANCELLED','CANCELED',
                        'POSTED','REIMBURSED','RECEIVED'
                   )
                   AND `seller_response_due` <= DATE_ADD(NOW(), INTERVAL ? HOUR)";
        $params = [$tid, max(1, $withinHours)];
        if ($channelId > 0) {
            $sql .= " AND `channel_id` = ?";
            $params[] = $channelId;
        }
        return (int) $this->db->query($sql, $params)->value;
    }

    /** @param list<int> $ids */
    public function markAlerted(array $ids): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$this->tenantId()]);
        $this->db->query(
            "UPDATE `#__sf_support_cases` SET `alerted_at` = NOW()
              WHERE `id` IN ({$placeholders}) AND `tenant_id` = ?",
            $params
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $channelId, string $kind, string $externalId): ?array
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_support_cases', $this->withTenant([
            'channel_id'  => $channelId,
            'kind'        => $kind,
            'external_id' => $externalId,
        ]));
        return $row === [] ? null : $row;
    }

    public function findById(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_support_cases', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forConversationContext(
        int $channelId,
        string $buyerLogin = '',
        string $orderId = '',
        string $itemId = '',
        int $limit = 8,
    ): array {
        if ($channelId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $clauses = ['`channel_id` = ?', '`tenant_id` = ?'];
        $params = [$channelId, $this->tenantId()];
        $ors = [];
        if (trim($buyerLogin) !== '') {
            $ors[] = '`buyer_login` = ?';
            $params[] = trim($buyerLogin);
        }
        if (trim($orderId) !== '') {
            $ors[] = '`order_id` = ?';
            $params[] = trim($orderId);
        }
        if (trim($itemId) !== '') {
            $ors[] = '`item_id` = ?';
            $params[] = trim($itemId);
        }
        if ($ors === []) {
            return [];
        }
        $clauses[] = '(' . implode(' OR ', $ors) . ')';
        $limit = max(1, min(20, $limit));
        $params[] = $limit;

        return $this->db->query(
            'SELECT * FROM `#__sf_support_cases`
              WHERE ' . implode(' AND ', $clauses) . '
              ORDER BY COALESCE(`last_modified_date`, `creation_date`) DESC, `id` DESC
              LIMIT ?',
            $params
        )->rows;
    }

    /**
     * @param array<string, mixed> $data
     * @return 'created'|'updated'
     */
    public function upsert(int $channelId, string $kind, string $externalId, array $data): string
    {
        if (!TenantContext::get()->has()) {
            throw new \RuntimeException('No tenant context.');
        }
        $existing = $this->find($channelId, $kind, $externalId);
        $payload = [
            'channel_id'          => $channelId,
            'external_id'         => $externalId,
            'kind'                => $kind,
            'case_type'           => (string) ($data['case_type'] ?? ''),
            'status'              => (string) ($data['status'] ?? ''),
            'item_id'             => (string) ($data['item_id'] ?? ''),
            'transaction_id'      => (string) ($data['transaction_id'] ?? ''),
            'order_id'            => (string) ($data['order_id'] ?? ''),
            'buyer_login'         => (string) ($data['buyer_login'] ?? ''),
            'claim_amount'        => $data['claim_amount'] ?? null,
            'claim_currency'      => (string) ($data['claim_currency'] ?? ''),
            'item_title'          => mb_substr((string) ($data['item_title'] ?? ''), 0, 255),
            'seller_response_due' => $data['seller_response_due'] ?? null,
            'creation_date'       => $data['creation_date'] ?? null,
            'last_modified_date'  => $data['last_modified_date'] ?? null,
            'summary_json'        => $this->encodeJson($data['summary'] ?? []),
            'synced_at'           => date('Y-m-d H:i:s'),
        ];
        if (array_key_exists('detail', $data)) {
            $payload['detail_json'] = $this->encodeJson($data['detail']);
        }

        if ($existing !== null) {
            $id = (int) $existing['id'];
            // Re-alert when the respond-by deadline changes.
            $oldDue = (string) ($existing['seller_response_due'] ?? '');
            $newDue = (string) ($payload['seller_response_due'] ?? '');
            if ($oldDue !== $newDue) {
                $payload['alerted_at'] = null;
            }
            unset($payload['channel_id'], $payload['external_id'], $payload['kind']);
            $this->db->update('sf_support_cases', $payload, $this->withTenant(['id' => $id]));
            return 'updated';
        }

        $this->db->insert('sf_support_cases', $this->withTenant($payload));
        return 'created';
    }

    /** @param mixed $data */
    private function encodeJson(mixed $data): string
    {
        if (is_string($data)) {
            return $data !== '' ? $data : '{}';
        }
        $json = json_encode(is_array($data) ? $data : [], JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '{}';
    }
}
