<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;

/**
 * Apply hold / attention automations after order upsert (mirrors ShippingRuleApplyService).
 */
final class OrderRuleApplyService
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    /**
     * @return array{applied: list<string>, on_hold: bool, attention_required: bool}|null
     */
    public function applyToOrderId(int $orderId): ?array
    {
        if ($orderId <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_orders` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$orderId, $this->tenantId()]
        )->row;
        if ($row === [] || $row === null) {
            return null;
        }
        return $this->applyToOrder($row);
    }

    /**
     * @param array<string, mixed> $order
     * @return array{applied: list<string>, on_hold: bool, attention_required: bool}|null
     */
    public function applyToOrder(array $order): ?array
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $orderId = (int) ($order['id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }

        $rules = (new OrderRuleRepository($this->db))->all(true);
        if ($rules === []) {
            return null;
        }

        $onHold = !empty($order['on_hold']);
        $attention = !empty($order['attention_required']);
        $applied = [];

        foreach ($rules as $rule) {
            if (!$this->matches($rule, $order, $orderId)) {
                continue;
            }
            $action = strtolower((string) ($rule['action'] ?? 'hold'));
            $label = (string) ($rule['name'] ?? $rule['type'] ?? 'rule');
            if ($action === 'attention') {
                $attention = true;
            } else {
                $onHold = true;
            }
            $applied[] = $label !== '' ? $label : (string) $rule['type'];
        }

        if ($applied === []) {
            return [
                'applied'             => [],
                'on_hold'             => $onHold,
                'attention_required'  => $attention,
            ];
        }

        $this->db->update('store_orders', [
            'on_hold'             => $onHold ? 1 : 0,
            'attention_required'  => $attention ? 1 : 0,
        ], $this->withTenant(['id' => $orderId]));

        return [
            'applied'            => $applied,
            'on_hold'            => $onHold,
            'attention_required' => $attention,
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $order
     */
    private function matches(array $rule, array $order, int $orderId): bool
    {
        $type = strtolower((string) ($rule['type'] ?? ''));
        $threshold = (float) ($rule['threshold'] ?? 0);

        return match ($type) {
            'unpaid' => empty($order['is_paid']),
            'high_value' => (float) ($order['total'] ?? 0) >= max(0.01, $threshold),
            'bad_address' => $this->isBadAddress($order),
            'unmapped' => $this->hasUnmappedLines($orderId),
            default => false,
        };
    }

    /** @param array<string, mixed> $order */
    private function isBadAddress(array $order): bool
    {
        $addr = $order['shipping_address'] ?? [];
        if (is_string($addr)) {
            $decoded = json_decode($addr, true);
            $addr = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($addr)) {
            $addr = [];
        }
        $line1 = trim((string) ($addr['addressLine1'] ?? $addr['address1'] ?? ''));
        $postcode = trim((string) ($addr['postalCode'] ?? $addr['postcode'] ?? ''));
        $name = trim((string) ($order['shipping_name'] ?? $addr['fullName'] ?? ''));
        return $line1 === '' || $postcode === '' || $name === '';
    }

    private function hasUnmappedLines(int $orderId): bool
    {
        $count = (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__store_order_items`
              WHERE `order_id` = ? AND `tenant_id` = ?
                AND (`variant_id` IS NULL OR `variant_id` = 0)",
            [$orderId, $this->tenantId()]
        )->value;
        return $count > 0;
    }
}
