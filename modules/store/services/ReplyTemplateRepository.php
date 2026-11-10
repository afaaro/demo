<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class ReplyTemplateRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(?string $channelType = null): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $params = [$this->tenantId()];
        $sql = "SELECT * FROM `#__store_reply_templates` WHERE `tenant_id` = ?";
        if ($channelType !== null && $channelType !== '') {
            $sql .= ' AND (`channel_type` IS NULL OR `channel_type` = ? OR `channel_type` = \'\')';
            $params[] = strtolower(trim($channelType));
        }
        $sql .= ' ORDER BY `name` ASC, `id` ASC';
        return $this->db->query($sql, $params)->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('store_reply_templates', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($name === '' || $body === '') {
            throw new RuntimeException('Name and body are required.');
        }
        $channelType = trim((string) ($data['channel_type'] ?? ''));
        $this->db->insert('store_reply_templates', $this->withTenant([
            'name'         => mb_substr($name, 0, 128),
            'body'         => $body,
            'channel_type' => $channelType !== '' ? strtolower(mb_substr($channelType, 0, 32)) : null,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]));
        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): void
    {
        if ($this->find($id) === null) {
            throw new RuntimeException('Template not found.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        if ($name === '' || $body === '') {
            throw new RuntimeException('Name and body are required.');
        }
        $channelType = trim((string) ($data['channel_type'] ?? ''));
        $this->db->update('store_reply_templates', [
            'name'         => mb_substr($name, 0, 128),
            'body'         => $body,
            'channel_type' => $channelType !== '' ? strtolower(mb_substr($channelType, 0, 32)) : null,
            'updated_at'   => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__store_reply_templates` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }
}
