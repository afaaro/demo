<?php
declare(strict_types=1);

namespace Module\Family\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class MarriageRepository
{
    public function __construct(
        private Database $db,
        private ?PersonRepository $people = null,
    ) {
        $this->people ??= new PersonRepository($db);
    }

    public function find(int $id): ?Marriage
    {
        $row = $this->db->query(
            $this->selectSql() . ' WHERE m.`id` = ? LIMIT 1',
            [$id]
        )->row;
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /**
     * @param array{q?:string,status?:string,husband_id?:int} $filters
     * @return array<int, Marriage>
     */
    public function search(array $filters, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildSearchWhere($filters);
        $rows = $this->db->query(
            $this->selectSql() . " {$where}
              ORDER BY CASE m.`status` WHEN 'active' THEN 0 ELSE 1 END, m.`id` DESC
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @param array{q?:string,status?:string,husband_id?:int} $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildSearchWhere($filters);
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM `#__family_marriages` m
               INNER JOIN `#__family_people` h ON h.`id` = m.`husband_id`
               INNER JOIN `#__family_people` w ON w.`id` = m.`wife_id`
              {$where}",
            $params
        )->row;
        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array<int, Marriage> */
    public function forPerson(int $personId, bool $activeOnly = false): array
    {
        $sql = $this->selectSql() . ' WHERE (m.`husband_id` = ? OR m.`wife_id` = ?)';
        $params = [$personId, $personId];
        if ($activeOnly) {
            $sql .= " AND m.`status` = 'active'";
        }
        $sql .= " ORDER BY CASE m.`status` WHEN 'active' THEN 0 ELSE 1 END, m.`married_on` IS NULL, m.`married_on` DESC, m.`id` DESC";
        $rows = $this->db->query($sql, $params)->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    public function countActiveWives(int $husbandId): int
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM `#__family_marriages`
              WHERE `husband_id` = ? AND `status` = 'active'",
            [$husbandId]
        )->row;
        return (int) ($row['cnt'] ?? 0);
    }

    public function wifeHasActiveMarriage(int $wifeId, ?int $exceptMarriageId = null): bool
    {
        $sql = "SELECT `id` FROM `#__family_marriages`
                 WHERE `wife_id` = ? AND `status` = 'active'";
        $params = [$wifeId];
        if ($exceptMarriageId !== null) {
            $sql .= ' AND `id` <> ?';
            $params[] = $exceptMarriageId;
        }
        $sql .= ' LIMIT 1';
        return $this->db->query($sql, $params)->row !== [];
    }

    /** @return array{total:int,active:int,divorced:int,widowed:int} */
    public function countsByStatus(): array
    {
        $rows = $this->db->query(
            "SELECT `status`, COUNT(*) AS cnt FROM `#__family_marriages` GROUP BY `status`"
        )->rows;
        $out = ['total' => 0, 'active' => 0, 'divorced' => 0, 'widowed' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);
            $out['total'] += $cnt;
            if (isset($out[$status])) {
                $out[$status] = $cnt;
            }
        }
        return $out;
    }

    public function create(array $data): Marriage
    {
        $husbandId = (int) ($data['husband_id'] ?? 0);
        $wifeId = (int) ($data['wife_id'] ?? 0);
        if ($husbandId <= 0 || $wifeId <= 0) {
            throw new InvalidArgumentException('Husband and wife are required.');
        }
        if ($husbandId === $wifeId) {
            throw new InvalidArgumentException('Husband and wife must be different people.');
        }

        $husband = $this->people->find($husbandId);
        $wife = $this->people->find($wifeId);
        if ($husband === null || $wife === null) {
            throw new RuntimeException('Husband or wife not found.');
        }
        if (!$husband->isMale()) {
            throw new InvalidArgumentException('Husband must be male.');
        }
        if (!$wife->isFemale()) {
            throw new InvalidArgumentException('Wife must be female.');
        }
        if ($husband->isDeceased() || $wife->isDeceased()) {
            throw new InvalidArgumentException('Cannot create a marriage involving a deceased person.');
        }

        $max = function_exists('family_max_wives') ? family_max_wives() : 4;
        $active = $this->countActiveWives($husbandId);
        if ($active >= $max) {
            throw new RuntimeException(
                "This husband already has {$active} active "
                . ($active === 1 ? 'wife' : 'wives')
                . " (max {$max}). Divorce one to marry again."
            );
        }
        if ($this->wifeHasActiveMarriage($wifeId)) {
            throw new RuntimeException('This woman already has an active marriage.');
        }

        // Remarriage of same couple after divorce is allowed as a new row.
        $activeSame = $this->db->query(
            "SELECT `id` FROM `#__family_marriages`
              WHERE `husband_id` = ? AND `wife_id` = ? AND `status` = 'active' LIMIT 1",
            [$husbandId, $wifeId]
        )->row;
        if ($activeSame !== []) {
            throw new RuntimeException('This couple already has an active marriage.');
        }

        $id = $this->db->insert('family_marriages', [
            'husband_id' => $husbandId,
            'wife_id'    => $wifeId,
            'status'     => 'active',
            'married_on' => $this->normalizeDate($data['married_on'] ?? null) ?? date('Y-m-d'),
            'ended_on'   => null,
        ]);

        return $this->find($id) ?? throw new RuntimeException('Failed to reload marriage.');
    }

    public function divorce(int $id, ?string $endedOn = null): Marriage
    {
        return $this->endMarriage($id, 'divorced', $endedOn);
    }

    public function markWidowed(int $id, ?string $endedOn = null): Marriage
    {
        $marriage = $this->find($id);
        if ($marriage === null) {
            throw new RuntimeException('Marriage not found.');
        }
        if (!$marriage->isActive()) {
            throw new RuntimeException('Only an active marriage can be marked widowed.');
        }

        $wife = $this->people->find($marriage->wifeId);
        $end = $this->normalizeDate($endedOn)
            ?? ($wife?->deathDate)
            ?? date('Y-m-d');

        if ($wife !== null && ($wife->deathDate === null || $wife->deathDate === '')) {
            $this->people->update($wife->id ?? 0, ['death_date' => $end]);
        }

        $this->db->update('family_marriages', [
            'status'   => 'widowed',
            'ended_on' => $end,
        ], ['id' => $id]);

        return $this->find($id) ?? throw new RuntimeException('Failed to reload marriage.');
    }

    public function delete(int $id): void
    {
        $this->db->query(
            'UPDATE `#__family_people` SET `marriage_id` = NULL WHERE `marriage_id` = ?',
            [$id]
        );
        $this->db->query('DELETE FROM `#__family_marriages` WHERE `id` = ?', [$id]);
    }

    private function endMarriage(int $id, string $status, ?string $endedOn): Marriage
    {
        $marriage = $this->find($id);
        if ($marriage === null) {
            throw new RuntimeException('Marriage not found.');
        }
        if (!$marriage->isActive()) {
            throw new RuntimeException('Only an active marriage can be ended.');
        }

        $this->db->update('family_marriages', [
            'status'   => $status,
            'ended_on' => $this->normalizeDate($endedOn) ?? date('Y-m-d'),
        ], ['id' => $id]);

        return $this->find($id) ?? throw new RuntimeException('Failed to reload marriage.');
    }

    private function selectSql(): string
    {
        return "SELECT m.*,
                       h.`name` AS husband_name,
                       h.`slug` AS husband_slug,
                       w.`name` AS wife_name,
                       w.`slug` AS wife_slug
                  FROM `#__family_marriages` m
                  INNER JOIN `#__family_people` h ON h.`id` = m.`husband_id`
                  INNER JOIN `#__family_people` w ON w.`id` = m.`wife_id`";
    }

    /**
     * @param array{q?:string,status?:string,husband_id?:int} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function buildSearchWhere(array $filters): array
    {
        $clauses = ['1=1'];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['active', 'divorced', 'widowed'], true)) {
            $clauses[] = 'm.`status` = ?';
            $params[] = $status;
        }

        $husbandId = (int) ($filters['husband_id'] ?? 0);
        if ($husbandId > 0) {
            $clauses[] = 'm.`husband_id` = ?';
            $params[] = $husbandId;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $clauses[] = '(h.`name` LIKE ? OR w.`name` LIKE ?)';
            array_push($params, $like, $like);
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function hydrate(array $row): Marriage
    {
        $m = new Marriage();
        $m->id          = (int) $row['id'];
        $m->husbandId   = (int) $row['husband_id'];
        $m->wifeId      = (int) $row['wife_id'];
        $m->status      = (string) $row['status'];
        $m->marriedOn   = $row['married_on'] ?? null;
        $m->endedOn     = $row['ended_on'] ?? null;
        $m->createdAt   = $row['created_at'] ?? null;
        $m->updatedAt   = $row['updated_at'] ?? null;
        $m->husbandName = isset($row['husband_name']) ? (string) $row['husband_name'] : null;
        $m->husbandSlug = isset($row['husband_slug']) ? (string) $row['husband_slug'] : null;
        $m->wifeName    = isset($row['wife_name']) ? (string) $row['wife_name'] : null;
        $m->wifeSlug    = isset($row['wife_slug']) ? (string) $row['wife_slug'] : null;
        return $m;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d', $ts);
    }
}
