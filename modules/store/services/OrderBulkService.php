<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

/**
 * Bulk warehouse/ops actions for the Orders list.
 */
final class OrderBulkService
{
    use TenantScoped;

    public function __construct(
        private Database $db,
        private OrderRepository $orders,
        private OrderDespatchService $despatch,
        private PackagingSizeRepository $packaging,
        private ShippingMethodRepository $methods,
        private ?OrderChannelActionService $channelActions = null,
    ) {}

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $opts
     * @return array{message: string, export?: string}
     */
    public function run(string $action, array $ids, array $opts = []): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id) => (int) $id, $ids),
            static fn(int $id) => $id > 0
        )));

        if ($action === 'export') {
            return ['message' => 'Export ready.', 'export' => $this->exportCsv($ids)];
        }

        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }

        if ($ids === []) {
            throw new RuntimeException('Select at least one order.');
        }

        return match ($action) {
            'alter_packaging' => $this->alterPackaging($ids, (int) ($opts['packaging_size_id'] ?? 0)),
            'alter_shipping'  => $this->alterShipping($ids, (int) ($opts['shipping_method_id'] ?? 0)),
            'cancel'          => $this->setStatus($ids, 'CANCELLED', keepStock: false),
            'cancel_keep'     => $this->setStatus($ids, 'CANCELLED', keepStock: true),
            'cancel_channel'  => $this->cancelOnChannel($ids),
            'clone'           => $this->cloneOrders($ids),
            'delete'          => $this->deleteOrders($ids),
            'pre_despatch'    => $this->flag($ids, ['pre_despatched' => 1], 'Pre-despatched'),
            'despatch'        => $this->despatchMany($ids),
            'hold'            => $this->flag($ids, ['on_hold' => 1], 'Held'),
            'unhold'          => $this->flag($ids, ['on_hold' => 0], 'Unheld'),
            'attention'       => $this->flag($ids, ['attention_required' => 1], 'Marked attention required'),
            'remove_attention'=> $this->flag($ids, ['attention_required' => 0], 'Cleared attention required'),
            'paid'            => $this->flag($ids, ['is_paid' => 1], 'Marked paid'),
            'priority'        => $this->flag($ids, ['is_priority' => 1], 'Marked priority'),
            default           => throw new RuntimeException('Unknown bulk action.'),
        };
    }

    /** @param list<int> $ids */
    private function cancelOnChannel(array $ids): array
    {
        $svc = $this->channelActions;
        if ($svc === null) {
            throw new RuntimeException('Channel cancel service is not configured for bulk actions.');
        }
        $ok = 0;
        $errors = 0;
        foreach ($ids as $id) {
            try {
                $svc->cancelOnChannelAndLocal($id);
                $ok++;
            } catch (\Throwable) {
                $errors++;
            }
        }
        $msg = "Channel-cancelled {$ok} order(s).";
        if ($errors > 0) {
            $msg .= " {$errors} failed.";
        }
        return ['message' => $msg];
    }

    /** @param list<int> $ids */
    private function alterPackaging(array $ids, int $packagingId): array
    {
        if ($packagingId <= 0 || $this->packaging->find($packagingId) === null) {
            throw new RuntimeException('Choose a packaging size.');
        }
        $n = $this->updateIds($ids, ['packaging_size_id' => $packagingId]);
        return ['message' => "Updated packaging on {$n} order(s)."];
    }

    /** @param list<int> $ids */
    private function alterShipping(array $ids, int $methodId): array
    {
        $method = $this->methods->find($methodId);
        if ($method === null) {
            throw new RuntimeException('Choose a shipping method.');
        }
        $label = trim((string) $method['method']);
        $n = $this->updateIds($ids, ['shipping_method' => $label]);
        return ['message' => "Updated shipping method on {$n} order(s)."];
    }

    /** @param list<int> $ids */
    private function setStatus(array $ids, string $status, bool $keepStock): array
    {
        $n = $this->updateIds($ids, ['status' => $status]);
        $suffix = $keepStock ? ' (stock kept allocated)' : '';
        return ['message' => "Cancelled {$n} order(s){$suffix}."];
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $fields
     */
    private function flag(array $ids, array $fields, string $verb): array
    {
        $n = $this->updateIds($ids, $fields);
        return ['message' => "{$verb} {$n} order(s)."];
    }

    /** @param list<int> $ids */
    private function despatchMany(array $ids): array
    {
        $ok = 0;
        $errors = 0;
        foreach ($ids as $id) {
            $data = $this->orders->find($id);
            if ($data === null) {
                $errors++;
                continue;
            }
            $order = $data['order'];
            if (strtoupper((string) ($order['status'] ?? '')) === 'CANCELLED') {
                $errors++;
                continue;
            }
            try {
                $tracking = trim((string) ($order['tracking_number'] ?? ''));
                if ($tracking === '') {
                    $tracking = 'LOCAL-' . $id;
                }
                $carrier = trim((string) ($order['carrier_code'] ?? ''));
                if ($carrier === '') {
                    $carrier = (string) ($order['shipping_method'] ?? 'OTHER');
                }
                $this->despatch->despatchOrder($order, $data['items'], $tracking, $carrier, false);
                $this->db->update('store_orders', [
                    'pre_despatched' => 1,
                    'on_hold'        => 0,
                ], $this->withTenant(['id' => $id]));
                $ok++;
            } catch (\Throwable) {
                $errors++;
            }
        }
        $msg = "Despatched {$ok} order(s).";
        if ($errors > 0) {
            $msg .= " {$errors} failed.";
        }
        return ['message' => $msg];
    }

    /** @param list<int> $ids */
    private function cloneOrders(array $ids): array
    {
        $n = 0;
        foreach ($ids as $id) {
            $data = $this->orders->find($id);
            if ($data === null) {
                continue;
            }
            $src = $data['order'];
            $cloneRef = 'CLONE-' . (string) $src['external_order_id'] . '-' . substr((string) microtime(true), -4);
            $newId = (int) $this->db->insert('store_orders', $this->withTenant([
                'channel_id'         => (int) $src['channel_id'],
                'external_order_id'  => mb_substr($cloneRef, 0, 64),
                'status'             => 'NOT_STARTED',
                'buyer_username'     => (string) ($src['buyer_username'] ?? ''),
                'buyer_email'        => (string) ($src['buyer_email'] ?? ''),
                'total'              => (float) ($src['total'] ?? 0),
                'currency'           => (string) ($src['currency'] ?? 'GBP'),
                'shipping_name'      => (string) ($src['shipping_name'] ?? ''),
                'shipping_address'   => (string) ($src['shipping_address'] ?? '{}'),
                'shipping_method'    => (string) ($src['shipping_method'] ?? ''),
                'packaging_size_id'  => $src['packaging_size_id'] ?? null,
                'package_count'      => (int) ($src['package_count'] ?? 1),
                'order_weight_kg'    => (float) ($src['order_weight_kg'] ?? 0),
                'on_hold'            => 0,
                'is_priority'        => (int) ($src['is_priority'] ?? 0),
                'attention_required' => 0,
                'is_paid'            => (int) ($src['is_paid'] ?? 1),
                'notes'              => 'Cloned from #' . (string) $src['external_order_id'],
                'created_at'         => date('Y-m-d H:i:s'),
            ]));
            foreach ($data['items'] as $item) {
                $this->db->insert('store_order_items', $this->withTenant([
                    'order_id'              => $newId,
                    'variant_id'            => $item['variant_id'] ?? null,
                    'external_sku'          => (string) ($item['external_sku'] ?? ''),
                    'title'                 => (string) ($item['title'] ?? ''),
                    'qty'                   => (int) ($item['qty'] ?? 1),
                    'price'                 => (float) ($item['price'] ?? 0),
                    'external_line_item_id' => '',
                ]));
            }
            $n++;
        }
        return ['message' => "Cloned {$n} order(s)."];
    }

    /** @param list<int> $ids */
    private function deleteOrders(array $ids): array
    {
        if (!TenantContext::get()->has()) {
            return ['message' => 'Deleted 0 order(s).'];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$this->tenantId()]);
        $this->db->query(
            "DELETE FROM `#__store_orders` WHERE `id` IN ({$placeholders}) AND `tenant_id` = ?",
            $params
        );
        return ['message' => 'Deleted ' . count($ids) . ' order(s).'];
    }

    /**
     * @param list<int> $ids
     * @param array<string, mixed> $fields
     */
    private function updateIds(array $ids, array $fields): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        $n = 0;
        foreach ($ids as $id) {
            if ($this->orders->find($id) === null) {
                continue;
            }
            $this->db->update('store_orders', $fields, $this->withTenant(['id' => $id]));
            $n++;
        }
        return $n;
    }

    /** @param list<int> $ids */
    private function exportCsv(array $ids): string
    {
        if (!TenantContext::get()->has()) {
            return '';
        }
        $rows = $this->orders->exportRows($ids);
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new RuntimeException('Unable to build export.');
        }
        fputcsv($fh, [
            'id', 'external_order_id', 'channel', 'status', 'buyer', 'email',
            'postcode', 'total', 'currency', 'shipping_method', 'packaging',
            'package_count', 'order_weight_kg', 'pickwave_id', 'notes', 'created_at',
        ]);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r['id'],
                $r['external_order_id'],
                $r['channel_label'],
                $r['status'],
                $r['shipping_name'],
                $r['buyer_email'],
                $r['postcode'],
                $r['total'],
                $r['currency'],
                $r['shipping_method'],
                $r['packaging_name'],
                $r['package_count'],
                $r['order_weight_kg'],
                $r['pickwave_id'],
                $r['notes'],
                $r['created_at'],
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }
}
