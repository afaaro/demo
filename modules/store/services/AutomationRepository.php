<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class AutomationRepository
{
    use TenantScoped;

    public const JOB_TYPES = [
        'orders_sync',
        'returns_sync',
        'tracking_poll',
        'conversations_sync',
        'cases_sync',
    ];

    public function __construct(private Database $db) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT a.*, c.`name` AS channel_name, c.`type` AS channel_type
               FROM `#__store_automations` a
          LEFT JOIN `#__store_channels` c
                 ON c.`id` = a.`channel_id` AND c.`tenant_id` = a.`tenant_id`
              WHERE a.`tenant_id` = ?
              ORDER BY a.`id` DESC",
            [$this->tenantId()]
        )->rows;
        return array_map([$this, 'hydrate'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT a.*, c.`name` AS channel_name, c.`type` AS channel_type
               FROM `#__store_automations` a
          LEFT JOIN `#__store_channels` c
                 ON c.`id` = a.`channel_id` AND c.`tenant_id` = a.`tenant_id`
              WHERE a.`id` = ? AND a.`tenant_id` = ?
              LIMIT 1",
            [$id, $this->tenantId()]
        )->row;
        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function save(?int $id, array $data): array
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $jobType = (string) ($data['job_type'] ?? 'orders_sync');
        if (!in_array($jobType, self::JOB_TYPES, true)) {
            throw new RuntimeException('Invalid job type.');
        }
        $payload = [
            'name'             => mb_substr(trim((string) ($data['name'] ?? 'Automation')), 0, 128),
            'job_type'         => $jobType,
            'channel_id'       => ((int) ($data['channel_id'] ?? 0)) > 0 ? (int) $data['channel_id'] : null,
            'interval_minutes' => max(5, (int) ($data['interval_minutes'] ?? 60)),
            'enabled'          => !empty($data['enabled']) ? 1 : 0,
            'updated_at'       => date('Y-m-d H:i:s'),
        ];
        if ($id === null || $id <= 0) {
            $payload['created_at'] = date('Y-m-d H:i:s');
            $payload['last_status'] = '';
            $newId = (int) $this->db->insert('store_automations', $this->withTenant($payload));
            $row = $this->find($newId);
        } else {
            $existing = $this->find($id);
            if ($existing === null) {
                throw new RuntimeException('Automation not found.');
            }
            $this->db->update('store_automations', $payload, $this->withTenant(['id' => $id]));
            $row = $this->find($id);
        }
        if ($row === null) {
            throw new RuntimeException('Failed to save automation.');
        }
        return $row;
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            'DELETE FROM `#__store_automations` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $this->tenantId()]
        );
    }

    public function markRun(int $id, string $status): void
    {
        $this->db->update('store_automations', [
            'last_run_at' => date('Y-m-d H:i:s'),
            'last_status' => mb_substr($status, 0, 32),
            'updated_at'  => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'name'             => (string) ($row['name'] ?? ''),
            'job_type'         => (string) ($row['job_type'] ?? ''),
            'channel_id'       => isset($row['channel_id']) && $row['channel_id'] !== null
                ? (int) $row['channel_id'] : null,
            'interval_minutes' => (int) ($row['interval_minutes'] ?? 60),
            'enabled'          => !empty($row['enabled']),
            'last_run_at'      => $row['last_run_at'] ?? null,
            'last_status'      => (string) ($row['last_status'] ?? ''),
            'channel_name'     => (string) ($row['channel_name'] ?? ''),
            'channel_type'     => (string) ($row['channel_type'] ?? ''),
            'created_at'       => (string) ($row['created_at'] ?? ''),
        ];
    }
}
