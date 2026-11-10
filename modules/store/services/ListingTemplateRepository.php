<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class ListingTemplateRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    public function find(int $id): ?ListingTemplate
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('store_listing_templates', $this->withTenant(['id' => $id]));
        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, ListingTemplate>
     */
    public function forChannel(int $channelId): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__store_listing_templates`
             WHERE `channel_id` = ? AND `tenant_id` = ?
             ORDER BY `is_default` DESC, `name` ASC",
            [$channelId, $this->tenantId()]
        )->rows;

        return array_map(fn(array $r) => $this->hydrate($r), $rows);
    }

    public function defaultForChannel(int $channelId): ?ListingTemplate
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_listing_templates`
             WHERE `channel_id` = ? AND `is_default` = 1 AND `tenant_id` = ?
             ORDER BY `id` ASC LIMIT 1",
            [$channelId, $this->tenantId()]
        )->row;

        return $row === [] ? null : $this->hydrate($row);
    }

    /**
     * @param array{name?: string, title_template?: string, description_template?: string, attributes_json?: string, is_default?: bool} $data
     */
    public function create(int $channelId, array $data): ListingTemplate
    {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Template name is required.');
        }

        $title = trim((string) ($data['title_template'] ?? '{{title}}'));
        if ($title === '') {
            $title = '{{title}}';
        }

        $description = (string) ($data['description_template'] ?? '{{description}}');
        if (trim($description) === '') {
            $description = '{{description}}';
        }

        $attributesJson = $this->encodeAttributes($data['attributes_json'] ?? $data['attributes'] ?? []);
        $isDefault = !empty($data['is_default']);
        $tid = $this->tenantId();

        return $this->db->transaction(function (Database $db) use (
            $channelId,
            $name,
            $title,
            $description,
            $attributesJson,
            $isDefault,
            $tid,
        ) {
            if ($isDefault) {
                $db->query(
                    "UPDATE `#__store_listing_templates` SET `is_default` = 0
                      WHERE `channel_id` = ? AND `tenant_id` = ?",
                    [$channelId, $tid]
                );
            }

            $id = $db->insert('store_listing_templates', [
                'tenant_id'            => $tid,
                'channel_id'           => $channelId,
                'name'                 => mb_substr($name, 0, 128),
                'title_template'       => mb_substr($title, 0, 255),
                'description_template' => $description,
                'attributes_json'      => $attributesJson,
                'is_default'           => $isDefault ? 1 : 0,
            ]);

            return $this->find((int) $id)
                ?? throw new RuntimeException('Failed to create listing template.');
        });
    }

    /**
     * @param array{name?: string, title_template?: string, description_template?: string, attributes_json?: string, is_default?: bool} $data
     */
    public function update(int $id, array $data): ListingTemplate
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Template not found.');
        }

        $name = array_key_exists('name', $data)
            ? trim((string) $data['name'])
            : $existing->name;
        if ($name === '') {
            throw new InvalidArgumentException('Template name is required.');
        }

        $title = array_key_exists('title_template', $data)
            ? trim((string) $data['title_template'])
            : $existing->titleTemplate;
        if ($title === '') {
            $title = '{{title}}';
        }

        $description = array_key_exists('description_template', $data)
            ? (string) $data['description_template']
            : $existing->descriptionTemplate;
        if (trim($description) === '') {
            $description = '{{description}}';
        }

        $attributesJson = array_key_exists('attributes_json', $data) || array_key_exists('attributes', $data)
            ? $this->encodeAttributes($data['attributes_json'] ?? $data['attributes'] ?? [])
            : $this->encodeAttributes($existing->attributes);

        $isDefault = array_key_exists('is_default', $data)
            ? !empty($data['is_default'])
            : $existing->isDefault;

        $tid = $this->tenantId();

        return $this->db->transaction(function (Database $db) use (
            $existing,
            $id,
            $name,
            $title,
            $description,
            $attributesJson,
            $isDefault,
            $tid,
        ) {
            if ($isDefault) {
                $db->query(
                    "UPDATE `#__store_listing_templates` SET `is_default` = 0
                      WHERE `channel_id` = ? AND `id` <> ? AND `tenant_id` = ?",
                    [$existing->channelId, $id, $tid]
                );
            }

            $db->update('store_listing_templates', [
                'name'                 => mb_substr($name, 0, 128),
                'title_template'       => mb_substr($title, 0, 255),
                'description_template' => $description,
                'attributes_json'      => $attributesJson,
                'is_default'           => $isDefault ? 1 : 0,
            ], ['id' => $id, 'tenant_id' => $tid]);

            return $this->find($id) ?? $existing;
        });
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->delete('store_listing_templates', $this->withTenant(['id' => $id]));
    }

    public function setDefault(int $channelId, int $templateId): void
    {
        if (!TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $this->db->transaction(function (Database $db) use ($channelId, $templateId, $tid) {
            $db->query(
                "UPDATE `#__store_listing_templates` SET `is_default` = 0
                  WHERE `channel_id` = ? AND `tenant_id` = ?",
                [$channelId, $tid]
            );
            $db->query(
                "UPDATE `#__store_listing_templates` SET `is_default` = 1
                 WHERE `id` = ? AND `channel_id` = ? AND `tenant_id` = ?",
                [$templateId, $channelId, $tid]
            );
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ListingTemplate
    {
        $t = new ListingTemplate();
        $t->id                  = (int) $row['id'];
        $t->channelId           = (int) $row['channel_id'];
        $t->name                = (string) $row['name'];
        $t->titleTemplate       = (string) $row['title_template'];
        $t->descriptionTemplate = (string) $row['description_template'];
        $t->attributes          = $this->decodeAttributes($row['attributes_json'] ?? null);
        $t->isDefault           = !empty($row['is_default']);
        $t->createdAt           = isset($row['created_at']) ? (string) $row['created_at'] : null;
        $t->updatedAt           = isset($row['updated_at']) ? (string) $row['updated_at'] : null;
        return $t;
    }

    /**
     * @return array<string, string>
     */
    private function decodeAttributes(mixed $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = json_decode((string) ($raw ?? ''), true);
            if (!is_array($decoded)) {
                return [];
            }
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            $name = trim((string) $key);
            if ($name === '' || !is_scalar($value)) {
                continue;
            }
            $out[$name] = (string) $value;
        }
        return $out;
    }

    private function encodeAttributes(mixed $raw): ?string
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return null;
            }
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                throw new InvalidArgumentException(
                    'Attribute map must be valid JSON object, e.g. {"product_type":"LUGGAGE","brand":"{{product_name}}"}.'
                );
            }
            $map = $this->decodeAttributes($decoded);
            return $map === [] ? null : json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($raw)) {
            $map = $this->decodeAttributes($raw);
            return $map === [] ? null : json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return null;
    }
}
