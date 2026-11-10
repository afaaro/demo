<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

final class AsnRepository
{
    use TenantScoped;

    public const STATUSES = ['draft', 'submitted', 'received'];

    public function __construct(private Database $db) {}

    /** @return list<array<string, mixed>> */
    public function forPo(int $poId): array
    {
        if ($poId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__store_asn`
              WHERE `po_id` = ? AND `tenant_id` = ?
              ORDER BY `id` DESC",
            [$poId, $this->tenantId()]
        )->rows;
        return array_map(fn(array $r) => $this->hydrate($r, true), $rows);
    }

    /** @return array{asn: array<string, mixed>, lines: list<array<string, mixed>>}|null */
    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_asn` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$id, $this->tenantId()]
        )->row;
        if ($row === []) {
            return null;
        }
        return [
            'asn'   => $this->hydrate($row, false),
            'lines' => $this->lines($id),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $asnId): array
    {
        if ($asnId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        return $this->db->query(
            "SELECT * FROM `#__store_asn_lines`
              WHERE `asn_id` = ? AND `tenant_id` = ?
              ORDER BY `id` ASC",
            [$asnId, $this->tenantId()]
        )->rows;
    }

    /**
     * @param list<array{sku:string, qty_expected:int}> $lines
     * @return array{asn: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    public function create(
        int $poId,
        ?string $expectedDate,
        int $palletCount,
        array $lines,
        string $notes = '',
        string $status = 'draft',
    ): array {
        if (!TenantContext::get()->has()) {
            throw new RuntimeException('No tenant context.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }
        $asnId = (int) $this->db->insert('store_asn', $this->withTenant([
            'po_id'         => $poId,
            'expected_date' => $expectedDate !== null && $expectedDate !== '' ? $expectedDate : null,
            'pallet_count'  => max(0, $palletCount),
            'status'        => $status,
            'notes'         => mb_substr($notes, 0, 500),
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]));
        foreach ($lines as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            $qty = max(0, (int) ($line['qty_expected'] ?? 0));
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $this->db->insert('store_asn_lines', $this->withTenant([
                'asn_id'       => $asnId,
                'sku'          => mb_substr($sku, 0, 128),
                'qty_expected' => $qty,
            ]));
        }
        $found = $this->find($asnId);
        if ($found === null) {
            throw new RuntimeException('Failed to create ASN.');
        }
        return $found;
    }

    public function setStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Invalid ASN status.');
        }
        if ($this->find($id) === null) {
            throw new RuntimeException('ASN not found.');
        }
        $this->db->update('store_asn', [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ], $this->withTenant(['id' => $id]));
    }

    /**
     * Compare latest submitted ASN vs scanned receive totals.
     *
     * @param array<string, int> $scanTotals sku_key => qty
     * @return array{asn_id:int|null, matches: list<array{sku:string, expected:int, scanned:int, delta:int}>, complete:bool}
     */
    public function compareToScans(int $poId, array $scanTotals): array
    {
        $asns = $this->forPo($poId);
        $target = null;
        foreach ($asns as $asn) {
            if (in_array((string) $asn['status'], ['submitted', 'received'], true)) {
                $target = $asn;
                break;
            }
        }
        if ($target === null && $asns !== []) {
            $target = $asns[0];
        }
        if ($target === null) {
            return ['asn_id' => null, 'matches' => [], 'complete' => true];
        }
        $matches = [];
        $complete = true;
        foreach ($this->lines((int) $target['id']) as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            $key = strtolower($sku);
            $expected = (int) ($line['qty_expected'] ?? 0);
            $scanned = (int) ($scanTotals[$key] ?? 0);
            $delta = $scanned - $expected;
            if ($delta !== 0) {
                $complete = false;
            }
            $matches[] = [
                'sku'      => $sku,
                'expected' => $expected,
                'scanned'  => $scanned,
                'delta'    => $delta,
            ];
        }
        return [
            'asn_id'   => (int) $target['id'],
            'matches'  => $matches,
            'complete' => $complete,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row, bool $withLineCount): array
    {
        $out = [
            'id'            => (int) $row['id'],
            'po_id'         => (int) ($row['po_id'] ?? 0),
            'expected_date' => $row['expected_date'] ?? null,
            'pallet_count'  => (int) ($row['pallet_count'] ?? 0),
            'status'        => (string) ($row['status'] ?? 'draft'),
            'notes'         => (string) ($row['notes'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
        ];
        if ($withLineCount) {
            $out['line_count'] = count($this->lines((int) $row['id']));
        }
        return $out;
    }
}
