<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class AttributeRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /** @return array<int, Attribute> */
    public function all(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT * FROM `#__store_attributes` WHERE `tenant_id` = ? ORDER BY `sort_order` ASC, `name` ASC",
            [$tid]
        )->rows;

        $attributes = array_map(fn($r) => $this->hydrate($r), $rows);

        // Load all values in one query.
        $values = $this->db->query(
            "SELECT * FROM `#__store_attribute_values` WHERE `tenant_id` = ? ORDER BY `attribute_id`, `sort_order`, `value`",
            [$tid]
        )->rows;

        $byAttr = [];
        foreach ($values as $v) {
            $byAttr[(int) $v['attribute_id']][] = $this->hydrateValue($v);
        }

        foreach ($attributes as $a) {
            $a->values = $byAttr[$a->id] ?? [];
        }

        return $attributes;
    }

    public function find(int $id): ?Attribute
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('store_attributes', $this->withTenant(['id' => $id]));
        if ($row === []) {
            return null;
        }
        $attr = $this->hydrate($row);
        $attr->values = $this->valuesFor($id);
        return $attr;
    }

    public function findByName(string $name): ?Attribute
    {
        $name = trim($name);
        if ($name === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_attributes`
              WHERE `tenant_id` = ? AND LOWER(`name`) = LOWER(?)
              LIMIT 1",
            [$this->tenantId(), $name]
        )->row;
        if ($row === []) {
            return null;
        }
        $attr = $this->hydrate($row);
        $attr->values = $this->valuesFor((int) $row['id']);

        return $attr;
    }

    public function ensureByName(string $name): Attribute
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return $existing;
        }
        $code = self::sanitizeCode($name);
        $byCode = $this->findByCode($code);
        if ($byCode !== null) {
            return $byCode;
        }

        return $this->create($code, $name);
    }

    public function findByCode(string $code): ?Attribute
    {
        $code = strtolower(trim($code));
        if ($code === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('store_attributes', $this->withTenant(['code' => $code]));
        if ($row === []) {
            return null;
        }
        $attr = $this->hydrate($row);
        $attr->values = $this->valuesFor((int) $row['id']);
        return $attr;
    }

    /** @return array<int, AttributeValue> */
    public function valuesFor(int $attributeId): array
    {
        if ($attributeId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__store_attribute_values`
              WHERE `attribute_id` = ? AND `tenant_id` = ?
              ORDER BY `sort_order`, `value`",
            [$attributeId, $this->tenantId()]
        )->rows;

        return array_map(fn($r) => $this->hydrateValue($r), $rows);
    }

    // --------------------------------------------------------------
    // Writes
    // --------------------------------------------------------------

    public static function sanitizeCode(string $code, string $fallbackSeed = ''): string
    {
        $original = trim($code);
        $code = strtolower($original);
        $code = (string) preg_replace('/[^a-z0-9]+/', '_', $code);
        $code = trim($code, '_');
        if (strlen($code) < 2) {
            $seed = $original !== '' ? $original : $fallbackSeed;
            $code = 'a_' . substr(sha1($seed !== '' ? $seed : 'attr'), 0, 8);
        }
        if (strlen($code) > 64) {
            $code = rtrim(substr($code, 0, 64), '_');
            if (strlen($code) < 2) {
                $code = 'a_' . substr(sha1($original !== '' ? $original : $fallbackSeed), 0, 8);
            }
        }

        return $code;
    }

    public function create(string $code, string $name, string $type = 'select', int $sortOrder = 0): Attribute
    {
        $name = trim($name);
        $code = self::sanitizeCode($code !== '' ? $code : $name, $name);

        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (!in_array($type, ['select','text','number'], true)) {
            throw new InvalidArgumentException("Invalid type: {$type}");
        }
        if ($this->findByCode($code) !== null) {
            throw new RuntimeException("Attribute '{$code}' already exists.");
        }

        $id = $this->db->insert('store_attributes', $this->withTenant([
            'code'       => $code,
            'name'       => $name,
            'type'       => $type,
            'sort_order' => $sortOrder,
        ]));

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload attribute.');
    }

    public function addValue(int $attributeId, string $value, string $label = '', int $sortOrder = 0): AttributeValue
    {
        $existing = $this->ensureValue($attributeId, $value, $label, $sortOrder);
        if ($existing === null) {
            throw new InvalidArgumentException('Value is required.');
        }

        return $existing;
    }

    /**
     * Create the attribute if missing, then add any missing values.
     *
     * @param list<string|array{value:string, label?:string}> $values
     */
    public function ensure(string $code, string $name, array $values = [], int $sortOrder = 0): Attribute
    {
        $code = self::sanitizeCode($code !== '' ? $code : $name, $name);
        $attr = $this->findByCode($code);
        if ($attr === null) {
            $attr = $this->create($code, $name, 'select', $sortOrder);
        }

        foreach (array_values($values) as $i => $item) {
            if (is_string($item)) {
                $value = $item;
                $label = $item;
            } else {
                $value = trim((string) ($item['value'] ?? ''));
                $label = trim((string) ($item['label'] ?? $value));
            }
            if ($value === '') {
                continue;
            }
            $this->ensureValue((int) $attr->id, $value, $label !== '' ? $label : $value, $i);
        }

        $fresh = $this->find((int) $attr->id);
        if ($fresh === null) {
            throw new RuntimeException("Failed to load attribute '{$code}'.");
        }

        return $fresh;
    }

    public function ensureValue(int $attributeId, string $value, string $label = '', int $sortOrder = 0): ?AttributeValue
    {
        $normalized = $this->normalizeValue($value);
        if ($normalized === null || $attributeId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $value = $normalized['value'];
        $label = trim($label);
        if ($label === '') {
            $label = $normalized['label'];
        }

        $found = $this->findValue($attributeId, $value, $label);
        if ($found !== null) {
            return $found;
        }

        if ($this->find($attributeId) === null) {
            throw new InvalidArgumentException('Attribute not found.');
        }

        try {
            $id = $this->db->insert('store_attribute_values', $this->withTenant([
                'attribute_id' => $attributeId,
                'value'        => $value,
                'label'        => $label !== '' ? $label : $value,
                'sort_order'   => $sortOrder,
            ]));

            return $this->hydrateValue(
                $this->db->first('store_attribute_values', $this->withTenant(['id' => $id]))
            );
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate') && !str_contains($e->getMessage(), '1062')) {
                throw $e;
            }
            return $this->findValue($attributeId, $value, $label);
        }
    }

    public function findValue(int $attributeId, string $value, string $label = ''): ?AttributeValue
    {
        if ($attributeId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $value = trim($value);
        $label = trim($label);
        if ($value === '') {
            return null;
        }

        $row = $this->db->query(
            "SELECT * FROM `#__store_attribute_values`
              WHERE `attribute_id` = ? AND `value` = ?
              LIMIT 1",
            [$attributeId, $value]
        )->row;
        if ($row !== []) {
            return $this->hydrateValue($row);
        }

        $row = $this->db->query(
            "SELECT * FROM `#__store_attribute_values`
              WHERE `attribute_id` = ? AND `tenant_id` = ?
                AND (LOWER(`value`) = LOWER(?) OR (`label` <> '' AND LOWER(`label`) = LOWER(?)))
              LIMIT 1",
            [$attributeId, $this->tenantId(), $value, $label !== '' ? $label : $value]
        )->row;
        if ($row !== []) {
            return $this->hydrateValue($row);
        }

        foreach ($this->valuesFor($attributeId) as $have) {
            if (CatalogSetupService::valuesMatch($value, $have->value)
                || ($have->label !== '' && CatalogSetupService::valuesMatch($value, $have->label))
                || ($label !== '' && CatalogSetupService::valuesMatch($label, $have->value))
            ) {
                return $have;
            }
        }

        return null;
    }

    /**
     * @return array{value: string, label: string}|null
     */
    private function normalizeValue(string $value): ?array
    {
        $value = trim($value);
        $value = str_replace(["\u{00d7}", "\u{2715}", "\u{ff0a}", "\u{fffd}"], 'x', $value);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if ($value === '' || preg_match('/^(?:\?|readonly|n\/a|-|–|—)$/iu', $value) === 1) {
            return null;
        }
        $label = $value;
        if (preg_match('/^(\d+)\s*x$/iu', $value, $m) === 1) {
            $label = $m[1] . ' ×';
            $value = $m[1];
        }
        if (strlen($value) > 128) {
            $value = mb_substr($value, 0, 128);
        }

        return ['value' => $value, 'label' => $label];
    }

    public function delete(int $attributeId): void
    {
        if ($attributeId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $this->db->query(
            "DELETE FROM `#__store_variant_attribute_values` WHERE `attribute_id` = ? AND `tenant_id` = ?",
            [$attributeId, $tid]
        );
        $this->db->query(
            "DELETE FROM `#__store_product_attributes` WHERE `attribute_id` = ?",
            [$attributeId]
        );
        $this->db->query(
            "DELETE FROM `#__store_attribute_values` WHERE `attribute_id` = ? AND `tenant_id` = ?",
            [$attributeId, $tid]
        );
        $this->db->query(
            "DELETE FROM `#__store_attributes` WHERE `id` = ? AND `tenant_id` = ?",
            [$attributeId, $tid]
        );
    }

    /** @param list<int> $ids */
    public function deleteMany(array $ids): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || $this->find($id) === null) {
                continue;
            }
            $this->delete($id);
            $n++;
        }

        return $n;
    }

    public function deleteSystemImported(): int
    {
        $ids = [];
        foreach ($this->all() as $attr) {
            if ($attr->id === null) {
                continue;
            }
            if (self::isSystemName($attr->code) || self::isSystemName($attr->name)) {
                $ids[] = (int) $attr->id;
            }
        }

        return $this->deleteMany($ids);
    }

    public static function isSystemName(string $name): bool
    {
        $norm = strtolower(trim($name));
        $norm = (string) preg_replace('/\s*\(read only\)\s*/i', '', $norm);
        $norm = trim((string) preg_replace('/[^a-z0-9]+/', ' ', $norm));
        if ($norm === '') {
            return true;
        }

        $keep = [
            'color', 'colour', 'size', 'pack size', 'thickness', 'pipe diameter',
            'insulation thickness', 'metres', 'meters', 'material', 'finish',
            'diameter', 'grade', 'style', 'pattern',
        ];
        if (in_array($norm, $keep, true)) {
            return false;
        }
        if (in_array($norm, ['id', 'ids', 'order', 'orders', 'invoice', 'invoices'], true)) {
            return true;
        }
        $compact = str_replace(' ', '', $norm);
        if (preg_match('/(?:^| )id$/', $norm) === 1) {
            return true;
        }
        if (
            preg_match('/id$/', $compact) === 1
            && preg_match('/(order|item|listing|invoice|transaction|channel|ebay|amazon|shopify|customer|user|external|variant|product|sku)/', $compact) === 1
        ) {
            return true;
        }
        if (preg_match('/\b(order|orders|invoice|invoices|transaction|payment)\b/', $norm) === 1) {
            return true;
        }
        if (preg_match('/\b(uuid|guid|external (?:id|ref|reference)|item number|listing number)\b/', $norm) === 1) {
            return true;
        }

        return false;
    }

    public function deleteValue(int $valueId): void
    {
        if ($valueId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__store_attribute_values` WHERE `id` = ? AND `tenant_id` = ?",
            [$valueId, $this->tenantId()]
        );
    }

    // --------------------------------------------------------------

    private function hydrate(array $row): Attribute
    {
        $a = new Attribute();
        $a->id        = (int)    $row['id'];
        $a->code      = (string) $row['code'];
        $a->name      = (string) $row['name'];
        $a->type      = (string) $row['type'];
        $a->sortOrder = (int)    ($row['sort_order'] ?? 0);
        return $a;
    }

    private function hydrateValue(array $row): AttributeValue
    {
        $v = new AttributeValue();
        $v->id          = (int)    $row['id'];
        $v->attributeId = (int)    $row['attribute_id'];
        $v->value       = (string) $row['value'];
        $v->label       = (string) ($row['label'] ?? '');
        $v->sortOrder   = (int)    ($row['sort_order'] ?? 0);
        return $v;
    }
}