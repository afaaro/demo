<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use System\Library\Database;

final class CustomerRepository
{
    use TenantScoped;

    private const SORTABLE = [
        'reference' => '`reference`',
        'name'      => '`name`',
        'created'   => '`created_at`',
        'company'   => '`bill_company`',
        'address1'  => '`bill_address1`',
        'city'      => '`bill_city`',
        'postcode'  => '`bill_postcode`',
        'email'     => '`email`',
    ];

    public function __construct(private Database $db) {}

    /**
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function search(
        array $filters = [],
        string $sort = 'created',
        string $dir = 'desc',
        int $limit = 200,
        int $offset = 0,
    ): array {
        if (!TenantContext::get()->has()) {
            return [];
        }
        [$where, $params] = $this->buildFilter($filters);
        $sortCol = self::SORTABLE[$sort] ?? self::SORTABLE['created'];
        $dirSql = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $rows = $this->db->query(
            "SELECT `id`, `reference`, `name`, `email`, `phone`, `is_business`,
                    `bill_company`, `bill_address1`, `bill_city`, `bill_postcode`,
                    `created_at`
               FROM `#__sf_customers`
              {$where}
              ORDER BY {$sortCol} {$dirSql}, `id` DESC
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->mapListRow($row);
        }
        return $out;
    }

    /** @param array<string, string> $filters */
    public function count(array $filters = []): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        [$where, $params] = $this->buildFilter($filters);
        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__sf_customers` {$where}",
            $params
        )->value;
    }

    /** @return list<array{id: int, name: string, reference: string}> */
    public function all(?string $search = null): array
    {
        $filters = [];
        if ($search !== null && trim($search) !== '') {
            $filters['q'] = trim($search);
        }
        return $this->search($filters, 'name', 'asc', 500, 0);
    }

    public function find(int $id): ?array
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_customers` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$id, $this->tenantId()]
        )->row;
        if ($row === []) {
            return null;
        }
        return $this->mapRow($row);
    }

    public function findByReference(string $reference): ?array
    {
        $reference = trim($reference);
        if ($reference === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_customers` WHERE `reference` = ? AND `tenant_id` = ? LIMIT 1",
            [$reference, $this->tenantId()]
        )->row;
        if ($row === []) {
            return null;
        }
        return $this->mapRow($row);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data = []): array
    {
        $now = date('Y-m-d H:i:s');
        $payload = $this->normalize($data);
        $ref = trim((string) ($data['reference'] ?? ''));
        $payload['reference'] = $ref !== '' ? mb_substr($ref, 0, 64) : $this->uniqueReference();
        if ($this->findByReference($payload['reference']) !== null) {
            throw new InvalidArgumentException("Customer reference '{$payload['reference']}' already exists.");
        }
        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;

        $id = (int) $this->db->insert('sf_customers', $this->withTenant($payload));
        $row = $this->find($id);
        if ($row === null) {
            throw new InvalidArgumentException('Failed to create customer.');
        }
        return $row;
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Customer not found.');
        }

        $payload = $this->normalize($data);
        if ((string) $payload['name'] === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if ((string) $payload['bill_address1'] === '') {
            throw new InvalidArgumentException('Billing Address 1 is required.');
        }
        if ((string) $payload['bill_city'] === '') {
            throw new InvalidArgumentException('Billing City is required.');
        }
        if ((string) $payload['bill_postcode'] === '') {
            throw new InvalidArgumentException('Billing Postcode is required.');
        }

        $payload['updated_at'] = date('Y-m-d H:i:s');
        unset($payload['reference'], $payload['created_at']);

        $sets = [];
        $params = [];
        foreach ($payload as $col => $val) {
            $sets[] = "`{$col}` = ?";
            $params[] = $val;
        }
        $params[] = $id;
        $params[] = $this->tenantId();
        $this->db->query(
            'UPDATE `#__sf_customers` SET ' . implode(', ', $sets) . ' WHERE `id` = ? AND `tenant_id` = ?',
            $params
        );

        $row = $this->find($id);
        if ($row === null) {
            throw new InvalidArgumentException('Customer not found after update.');
        }
        return $row;
    }

    public function duplicate(int $id): array
    {
        $src = $this->find($id);
        if ($src === null) {
            throw new InvalidArgumentException('Customer not found.');
        }
        $data = $src;
        unset($data['id'], $data['created_at'], $data['updated_at']);
        $data['reference'] = $this->uniqueReference();
        $data['name'] = trim((string) $data['name']) !== ''
            ? ((string) $data['name'] . ' (Copy)')
            : 'Copy';
        $data['is_business'] = !empty($data['is_business']) ? 1 : 0;
        return $this->create($data);
    }

    /** @param list<int> $ids */
    public function deleteMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn($id) => (int) $id, $ids),
            static fn(int $id) => $id > 0
        )));
        if ($ids === [] || !TenantContext::get()->has()) {
            return 0;
        }
        $ph = implode(', ', array_fill(0, count($ids), '?'));
        $this->db->query(
            "DELETE FROM `#__sf_customers` WHERE `id` IN ({$ph}) AND `tenant_id` = ?",
            [...$ids, $this->tenantId()]
        );
        return count($ids);
    }

    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            'DELETE FROM `#__sf_customers` WHERE `id` = ? AND `tenant_id` = ?',
            [$id, $this->tenantId()]
        );
    }

    /**
     * Upsert customers from order buyer / ship-to data.
     * @return int Number of new customers created
     */
    public function syncFromOrders(): int
    {
        if (!TenantContext::get()->has()) {
            return 0;
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT o.`id`, o.`buyer_username`, o.`buyer_email`, o.`shipping_name`,
                    o.`shipping_address`, o.`created_at`
               FROM `#__sf_orders` o
              WHERE o.`tenant_id` = ?
              ORDER BY o.`created_at` DESC, o.`id` DESC
              LIMIT 2000",
            [$tid]
        )->rows;

        $created = 0;
        $seen = [];
        foreach ($rows as $row) {
            $username = trim((string) ($row['buyer_username'] ?? ''));
            $email = trim((string) ($row['buyer_email'] ?? ''));
            $name = trim((string) ($row['shipping_name'] ?? ''));
            $addr = [];
            $raw = $row['shipping_address'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $addr = $decoded;
                }
            } elseif (is_array($raw)) {
                $addr = $raw;
            }

            if ($name === '') {
                $name = trim((string) ($addr['fullName'] ?? $addr['name'] ?? ''));
            }
            if ($email === '') {
                $email = trim((string) ($addr['email'] ?? ''));
            }
            $phone = trim((string) ($addr['primaryPhone'] ?? $addr['phone'] ?? ''));
            $company = trim((string) ($addr['company'] ?? $addr['companyName'] ?? ''));
            $line1 = trim((string) ($addr['addressLine1'] ?? $addr['address1'] ?? ''));
            $line2 = trim((string) ($addr['addressLine2'] ?? $addr['address2'] ?? ''));
            $city = trim((string) ($addr['city'] ?? ''));
            $county = trim((string) ($addr['stateOrProvince'] ?? $addr['county'] ?? $addr['state'] ?? ''));
            $postcode = trim((string) ($addr['postalCode'] ?? $addr['postcode'] ?? ''));
            $country = strtoupper(trim((string) ($addr['countryCode'] ?? $addr['country'] ?? 'GB')));
            if ($country === '') {
                $country = 'GB';
            }

            $reference = $username !== '' ? mb_substr($username, 0, 64) : '';
            if ($reference === '') {
                $key = strtolower($email !== '' ? $email : ($name . '|' . $postcode . '|' . $line1));
                if ($key === '|' || $key === '') {
                    continue;
                }
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($email !== '' && $this->findByEmail($email) !== null) {
                    continue;
                }
                $reference = $this->uuidReference();
            } else {
                $key = 'ref:' . strtolower($reference);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                if ($this->findByReference($reference) !== null) {
                    continue;
                }
            }

            try {
                $this->create([
                    'reference'     => $reference,
                    'name'          => $name,
                    'email'         => $email,
                    'phone'         => $phone,
                    'bill_company'  => $company,
                    'bill_address1' => $line1,
                    'bill_address2' => $line2,
                    'bill_city'     => $city,
                    'bill_county'   => $county,
                    'bill_country'  => $country,
                    'bill_postcode' => $postcode,
                    'ship_company'  => $company,
                    'ship_address1' => $line1,
                    'ship_address2' => $line2,
                    'ship_city'     => $city,
                    'ship_county'   => $county,
                    'ship_country'  => $country,
                    'ship_postcode' => $postcode,
                    'created_at'    => (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
                ]);
                // Preserve order created_at when provided
                if (!empty($row['created_at'])) {
                    $this->db->query(
                        "UPDATE `#__sf_customers` SET `created_at` = ? WHERE `reference` = ? AND `tenant_id` = ?",
                        [(string) $row['created_at'], $reference, $tid]
                    );
                }
                $created++;
            } catch (\Throwable) {
                // skip duplicates / bad rows
            }
        }

        return $created;
    }

    public function findByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__sf_customers` WHERE LOWER(`email`) = LOWER(?) AND `tenant_id` = ? LIMIT 1",
            [$email, $this->tenantId()]
        )->row;
        if ($row === []) {
            return null;
        }
        return $this->mapRow($row);
    }

    public function orderCountByEmail(string $email): int
    {
        return $this->orderStats(['email' => $email])['count'];
    }

    /**
     * Order totals for a customer matched by email and/or marketplace username (reference).
     *
     * @param array{email?: string, reference?: string} $identity
     * @return array{count: int, spend: float, currency: string}
     */
    public function orderStats(array $identity): array
    {
        if (!TenantContext::get()->has()) {
            return ['count' => 0, 'spend' => 0.0, 'currency' => 'GBP'];
        }
        [$where, $params] = $this->orderMatchClause($identity);
        if ($where === '') {
            return ['count' => 0, 'spend' => 0.0, 'currency' => 'GBP'];
        }

        $row = $this->db->query(
            "SELECT COUNT(*) AS `cnt`,
                    COALESCE(SUM(o.`total`), 0) AS `spend`,
                    COALESCE(MAX(o.`currency`), 'GBP') AS `currency`
               FROM `#__sf_orders` o
              WHERE {$where} AND o.`tenant_id` = ?",
            [...$params, $this->tenantId()]
        )->row;

        return [
            'count'    => (int) ($row['cnt'] ?? 0),
            'spend'    => (float) ($row['spend'] ?? 0),
            'currency' => (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'GBP'),
        ];
    }

    /**
     * Recent orders for a customer.
     *
     * @param array{email?: string, reference?: string} $identity
     * @return list<array{
     *   id: int, external_order_id: string, status: string, total: float,
     *   currency: string, created_at: string, channel_label: string, buyer_email: string
     * }>
     */
    public function recentOrders(array $identity, int $limit = 15): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        [$where, $params] = $this->orderMatchClause($identity);
        if ($where === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT o.`id`, o.`external_order_id`, o.`status`, o.`total`, o.`currency`,
                    o.`created_at`, o.`buyer_email`,
                    c.`label` AS channel_label, c.`name` AS channel_name
               FROM `#__sf_orders` o
               LEFT JOIN `#__sf_channels` c
                 ON c.`id` = o.`channel_id` AND c.`tenant_id` = o.`tenant_id`
              WHERE {$where} AND o.`tenant_id` = ?
              ORDER BY o.`created_at` DESC, o.`id` DESC
              LIMIT ?",
            [...$params, $tid, $limit]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'                => (int) $row['id'],
                'external_order_id' => (string) ($row['external_order_id'] ?? ''),
                'status'            => (string) ($row['status'] ?? ''),
                'total'             => (float) ($row['total'] ?? 0),
                'currency'          => (string) (($row['currency'] ?? '') !== '' ? $row['currency'] : 'GBP'),
                'created_at'        => (string) ($row['created_at'] ?? ''),
                'channel_label'     => (string) ($row['channel_label'] ?? $row['channel_name'] ?? ''),
                'buyer_email'       => (string) ($row['buyer_email'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Marketplace mail / conversation snippets for Correspondence panel.
     *
     * @param array{email?: string, reference?: string} $identity
     * @return list<array{
     *   kind: string, id: int, channel_id: int, channel_label: string,
     *   subject: string, preview: string, party: string, at: string, unread: bool
     * }>
     */
    public function recentCorrespondence(array $identity, int $limit = 12): array
    {
        $email = trim((string) ($identity['email'] ?? ''));
        $reference = trim((string) ($identity['reference'] ?? ''));
        if (($email === '' && $reference === '') || !TenantContext::get()->has()) {
            return [];
        }

        $limit = max(1, min(40, $limit));
        $tid = $this->tenantId();
        $items = [];

        $msgParts = [];
        $msgParams = [];
        if ($reference !== '') {
            $msgParts[] = '(m.`sender` LIKE ? OR m.`recipient` LIKE ?)';
            $like = '%' . $reference . '%';
            $msgParams[] = $like;
            $msgParams[] = $like;
        }
        if ($email !== '') {
            $msgParts[] = '(LOWER(m.`sender`) = LOWER(?) OR LOWER(m.`recipient`) = LOWER(?)
                OR m.`sender` LIKE ? OR m.`recipient` LIKE ?)';
            $emailLike = '%' . $email . '%';
            array_push($msgParams, $email, $email, $emailLike, $emailLike);
        }

        if ($msgParts !== []) {
            $rows = $this->db->query(
                "SELECT m.`id`, m.`external_id`, m.`channel_id`, m.`subject`, m.`content`,
                        m.`sender`, m.`recipient`, m.`receive_date`, m.`is_read`,
                        c.`label` AS channel_label, c.`name` AS channel_name
                   FROM `#__sf_messages` m
                   LEFT JOIN `#__sf_channels` c
                     ON c.`id` = m.`channel_id` AND c.`tenant_id` = m.`tenant_id`
                  WHERE m.`tenant_id` = ? AND (" . implode(' OR ', $msgParts) . ")
                  ORDER BY m.`receive_date` DESC, m.`id` DESC
                  LIMIT ?",
                array_merge([$tid], $msgParams, [$limit])
            )->rows;

            foreach ($rows as $row) {
                $rawPreview = preg_replace('/\s+/', ' ', strip_tags((string) ($row['content'] ?? '')));
                $preview = trim(is_string($rawPreview) ? $rawPreview : '');
                $items[] = [
                    'kind'          => 'message',
                    'id'            => (int) $row['id'],
                    'external_id'   => (string) ($row['external_id'] ?? ''),
                    'channel_id'    => (int) ($row['channel_id'] ?? 0),
                    'channel_label' => (string) ($row['channel_label'] ?? $row['channel_name'] ?? ''),
                    'subject'       => (string) ($row['subject'] ?? ''),
                    'preview'       => mb_strimwidth($preview, 0, 140, '…'),
                    'party'         => (string) ($row['sender'] ?? ''),
                    'at'            => (string) ($row['receive_date'] ?? ''),
                    'unread'        => (int) ($row['is_read'] ?? 1) === 0,
                ];
            }
        }

        if ($reference !== '') {
            $convRows = $this->db->query(
                "SELECT cv.`id`, cv.`channel_id`, cv.`external_id`, cv.`latest_body`, cv.`latest_sender`,
                        cv.`latest_date`, cv.`unread_count`, cv.`reference_id`,
                        c.`label` AS channel_label, c.`name` AS channel_name
                   FROM `#__sf_conversations` cv
                   LEFT JOIN `#__sf_channels` c
                     ON c.`id` = cv.`channel_id` AND c.`tenant_id` = cv.`tenant_id`
                  WHERE cv.`tenant_id` = ?
                    AND (cv.`latest_sender` = ?
                     OR cv.`external_id` LIKE ?
                     OR cv.`reference_id` LIKE ?)
                  ORDER BY cv.`latest_date` DESC, cv.`id` DESC
                  LIMIT ?",
                [$tid, $reference, '%' . $reference . '%', '%' . $reference . '%', $limit]
            )->rows;

            foreach ($convRows as $row) {
                $items[] = [
                    'kind'          => 'conversation',
                    'id'            => (int) $row['id'],
                    'channel_id'    => (int) ($row['channel_id'] ?? 0),
                    'channel_label' => (string) ($row['channel_label'] ?? $row['channel_name'] ?? ''),
                    'subject'       => 'Conversation',
                    'preview'       => mb_strimwidth((string) ($row['latest_body'] ?? ''), 0, 140, '…'),
                    'party'         => (string) ($row['latest_sender'] ?? ''),
                    'at'            => (string) ($row['latest_date'] ?? ''),
                    'unread'        => (int) ($row['unread_count'] ?? 0) > 0,
                    'external_id'   => (string) ($row['external_id'] ?? ''),
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['at'] ?? ''), (string) ($a['at'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * @param list<array{id: int, email?: string, reference?: string}> $customers
     * @return array<int, array{count: int, spend: float}>
     */
    public function orderStatsForMany(array $customers): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $out = [];
        $byEmail = [];
        $byRef = [];
        foreach ($customers as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[$id] = ['count' => 0, 'spend' => 0.0];
            $email = strtolower(trim((string) ($c['email'] ?? '')));
            $ref = trim((string) ($c['reference'] ?? ''));
            if ($email !== '') {
                $byEmail[$email][] = $id;
            }
            if ($ref !== '') {
                $byRef[$ref][] = $id;
            }
        }

        if ($byEmail === [] && $byRef === []) {
            return $out;
        }

        $parts = [];
        $params = [];
        if ($byEmail !== []) {
            $emails = array_keys($byEmail);
            $ph = implode(', ', array_fill(0, count($emails), '?'));
            $parts[] = "LOWER(o.`buyer_email`) IN ({$ph})";
            foreach ($emails as $email) {
                $params[] = $email;
            }
        }
        if ($byRef !== []) {
            $refs = array_keys($byRef);
            $ph = implode(', ', array_fill(0, count($refs), '?'));
            $parts[] = "o.`buyer_username` IN ({$ph})";
            foreach ($refs as $ref) {
                $params[] = $ref;
            }
        }

        $rows = $this->db->query(
            "SELECT LOWER(o.`buyer_email`) AS `email_key`, o.`buyer_username` AS `ref_key`,
                    COUNT(*) AS `cnt`, COALESCE(SUM(o.`total`), 0) AS `spend`
               FROM `#__sf_orders` o
              WHERE o.`tenant_id` = ? AND (" . implode(' OR ', $parts) . ")
              GROUP BY LOWER(o.`buyer_email`), o.`buyer_username`",
            array_merge([$this->tenantId()], $params)
        )->rows;

        foreach ($rows as $row) {
            $cnt = (int) ($row['cnt'] ?? 0);
            $spend = (float) ($row['spend'] ?? 0);
            $emailKey = strtolower(trim((string) ($row['email_key'] ?? '')));
            $refKey = trim((string) ($row['ref_key'] ?? ''));
            $matched = [];
            if ($emailKey !== '' && isset($byEmail[$emailKey])) {
                foreach ($byEmail[$emailKey] as $id) {
                    $matched[$id] = true;
                }
            }
            if ($refKey !== '' && isset($byRef[$refKey])) {
                foreach ($byRef[$refKey] as $id) {
                    $matched[$id] = true;
                }
            }
            foreach (array_keys($matched) as $id) {
                $out[$id]['count'] += $cnt;
                $out[$id]['spend'] += $spend;
            }
        }

        return $out;
    }

    /**
     * @param array{email?: string, reference?: string} $identity
     * @return array{0: string, 1: list<mixed>}
     */
    private function orderMatchClause(array $identity): array
    {
        $email = trim((string) ($identity['email'] ?? ''));
        $reference = trim((string) ($identity['reference'] ?? ''));
        $parts = [];
        $params = [];

        if ($email !== '') {
            $parts[] = 'LOWER(o.`buyer_email`) = LOWER(?)';
            $params[] = $email;
        }
        if ($reference !== '') {
            $parts[] = 'o.`buyer_username` = ?';
            $params[] = $reference;
        }

        if ($parts === []) {
            return ['', []];
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
    }

    /**
     * @param array<string, string> $filters
     * @return array{0: string, 1: list<mixed>}
     */
    private function buildFilter(array $filters): array
    {
        $parts = [$this->tenantClause()];
        $params = [$this->tenantId()];

        $like = static function (string $col, string $val) use (&$parts, &$params): void {
            $val = trim($val);
            if ($val === '') {
                return;
            }
            $parts[] = "{$col} LIKE ?";
            $params[] = '%' . $val . '%';
        };

        if (($filters['q'] ?? '') !== '') {
            $q = '%' . trim($filters['q']) . '%';
            $parts[] = '(`name` LIKE ? OR `email` LIKE ? OR `reference` LIKE ? OR `phone` LIKE ? OR `bill_company` LIKE ? OR `bill_postcode` LIKE ?)';
            array_push($params, $q, $q, $q, $q, $q, $q);
        }

        $like('`reference`', (string) ($filters['reference'] ?? ''));
        $like('`name`', (string) ($filters['name'] ?? ''));
        $like('`bill_company`', (string) ($filters['company'] ?? ''));
        $like('`bill_address1`', (string) ($filters['address1'] ?? ''));
        $like('`bill_city`', (string) ($filters['city'] ?? ''));
        $like('`bill_postcode`', (string) ($filters['postcode'] ?? ''));
        $like('`email`', (string) ($filters['email'] ?? ''));

        $created = trim((string) ($filters['created'] ?? ''));
        if ($created !== '') {
            $parts[] = "DATE_FORMAT(`created_at`, '%d/%m/%Y %H:%i') LIKE ?";
            $params[] = '%' . $created . '%';
        }

        $where = $parts === [] ? '' : ('WHERE ' . implode(' AND ', $parts));
        return [$where, $params];
    }

    private function uniqueReference(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $ref = $this->generateReference();
            if ($this->findByReference($ref) === null) {
                return $ref;
            }
        }
        throw new InvalidArgumentException('Could not generate a unique customer reference.');
    }

    private function generateReference(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        $bytes = random_bytes(8);
        for ($i = 0; $i < 8; $i++) {
            $out .= $alphabet[ord($bytes[$i]) % ($max + 1)];
        }
        return $out;
    }

    private function uuidReference(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return strtoupper(sprintf(
            '%s-%s-%s-%s-%s',
            substr($h, 0, 8),
            substr($h, 8, 4),
            substr($h, 12, 4),
            substr($h, 16, 4),
            substr($h, 20, 12)
        ));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalize(array $data): array
    {
        $str = static function (mixed $v, int $max): string {
            return mb_substr(trim((string) ($v ?? '')), 0, $max);
        };

        return [
            'name'          => $str($data['name'] ?? '', 191),
            'email'         => $str($data['email'] ?? '', 191),
            'email_cc'      => $str($data['email_cc'] ?? '', 512),
            'phone'         => $str($data['phone'] ?? '', 64),
            'vat_number'    => $str($data['vat_number'] ?? '', 64),
            'air_number'    => $str($data['air_number'] ?? '', 64),
            'ukims_number'  => $str($data['ukims_number'] ?? '', 64),
            'eori_number'   => $str($data['eori_number'] ?? '', 64),
            'is_business'   => !empty($data['is_business']) ? 1 : 0,
            'notes'         => trim((string) ($data['notes'] ?? '')),
            'bill_company'  => $str($data['bill_company'] ?? '', 191),
            'bill_address1' => $str($data['bill_address1'] ?? '', 191),
            'bill_address2' => $str($data['bill_address2'] ?? '', 191),
            'bill_city'     => $str($data['bill_city'] ?? '', 128),
            'bill_county'   => $str($data['bill_county'] ?? '', 128),
            'bill_country'  => $str($data['bill_country'] ?? 'GB', 64) ?: 'GB',
            'bill_postcode' => $str($data['bill_postcode'] ?? '', 32),
            'ship_company'  => $str($data['ship_company'] ?? '', 191),
            'ship_address1' => $str($data['ship_address1'] ?? '', 191),
            'ship_address2' => $str($data['ship_address2'] ?? '', 191),
            'ship_city'     => $str($data['ship_city'] ?? '', 128),
            'ship_county'   => $str($data['ship_county'] ?? '', 128),
            'ship_country'  => $str($data['ship_country'] ?? 'GB', 64) ?: 'GB',
            'ship_postcode' => $str($data['ship_postcode'] ?? '', 32),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapListRow(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'reference'     => (string) $row['reference'],
            'name'          => (string) ($row['name'] ?? ''),
            'email'         => (string) ($row['email'] ?? ''),
            'phone'         => (string) ($row['phone'] ?? ''),
            'is_business'   => (int) ($row['is_business'] ?? 0) === 1,
            'bill_company'  => (string) ($row['bill_company'] ?? ''),
            'bill_address1' => (string) ($row['bill_address1'] ?? ''),
            'bill_city'     => (string) ($row['bill_city'] ?? ''),
            'bill_postcode' => (string) ($row['bill_postcode'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function mapRow(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'reference'     => (string) $row['reference'],
            'name'          => (string) ($row['name'] ?? ''),
            'email'         => (string) ($row['email'] ?? ''),
            'email_cc'      => (string) ($row['email_cc'] ?? ''),
            'phone'         => (string) ($row['phone'] ?? ''),
            'vat_number'    => (string) ($row['vat_number'] ?? ''),
            'air_number'    => (string) ($row['air_number'] ?? ''),
            'ukims_number'  => (string) ($row['ukims_number'] ?? ''),
            'eori_number'   => (string) ($row['eori_number'] ?? ''),
            'is_business'   => (int) ($row['is_business'] ?? 0) === 1,
            'notes'         => (string) ($row['notes'] ?? ''),
            'bill_company'  => (string) ($row['bill_company'] ?? ''),
            'bill_address1' => (string) ($row['bill_address1'] ?? ''),
            'bill_address2' => (string) ($row['bill_address2'] ?? ''),
            'bill_city'     => (string) ($row['bill_city'] ?? ''),
            'bill_county'   => (string) ($row['bill_county'] ?? ''),
            'bill_country'  => (string) ($row['bill_country'] ?? 'GB'),
            'bill_postcode' => (string) ($row['bill_postcode'] ?? ''),
            'ship_company'  => (string) ($row['ship_company'] ?? ''),
            'ship_address1' => (string) ($row['ship_address1'] ?? ''),
            'ship_address2' => (string) ($row['ship_address2'] ?? ''),
            'ship_city'     => (string) ($row['ship_city'] ?? ''),
            'ship_county'   => (string) ($row['ship_county'] ?? ''),
            'ship_country'  => (string) ($row['ship_country'] ?? 'GB'),
            'ship_postcode' => (string) ($row['ship_postcode'] ?? ''),
            'created_at'    => (string) ($row['created_at'] ?? ''),
            'updated_at'    => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
