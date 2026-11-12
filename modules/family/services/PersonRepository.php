<?php
declare(strict_types=1);

namespace Module\Family\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class PersonRepository
{
    public function __construct(private Database $db) {}

    public function find(int $id): ?Person
    {
        $row = $this->db->query(
            $this->selectSql() . ' WHERE p.`id` = ? LIMIT 1',
            [$id]
        )->row;
        return $row !== [] ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Person
    {
        $row = $this->db->query(
            $this->selectSql() . ' WHERE p.`slug` = ? LIMIT 1',
            [$slug]
        )->row;
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /**
     * @param array{q?:string,gender?:string,active?:string} $filters
     * @return array<int, Person>
     */
    public function search(
        array $filters,
        int $limit = 50,
        int $offset = 0,
        string $sort = 'name',
        string $dir = 'asc'
    ): array {
        [$where, $params] = $this->buildSearchWhere($filters);
        $allowed = [
            'id'         => 'p.`id`',
            'name'       => 'p.`name`',
            'slug'       => 'p.`slug`',
            'gender'     => 'p.`gender`',
            'birth_date' => 'p.`birth_date`',
            'created_at' => 'p.`created_at`',
        ];
        $sortSql = $allowed[$sort] ?? $allowed['name'];
        $direction = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';

        $rows = $this->db->query(
            $this->selectSql() . " {$where} ORDER BY {$sortSql} {$direction}, p.`id` ASC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @param array{q?:string,gender?:string,active?:string} $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildSearchWhere($filters);
        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM `#__family_people` p {$where}",
            $params
        )->row;
        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array<int, Person> */
    public function options(?string $gender = null, bool $activeOnly = true): array
    {
        $filters = [];
        if ($gender !== null && $gender !== '') {
            $filters['gender'] = $gender;
        }
        if ($activeOnly) {
            $filters['active'] = '1';
        }
        return $this->search($filters, 500, 0, 'name', 'asc');
    }

    /** @return array<int, Person> */
    public function childrenOf(int $personId): array
    {
        $rows = $this->db->query(
            $this->selectSql() . '
              WHERE p.`father_id` = ? OR p.`mother_id` = ?
              ORDER BY p.`birth_date` IS NULL, p.`birth_date`, p.`name`',
            [$personId, $personId]
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, Person> */
    public function childrenOfCouple(int $fatherId, int $motherId): array
    {
        $rows = $this->db->query(
            $this->selectSql() . '
              WHERE p.`father_id` = ? AND p.`mother_id` = ?
              ORDER BY p.`birth_date` IS NULL, p.`birth_date`, p.`name`',
            [$fatherId, $motherId]
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, Person> */
    public function siblingsOf(Person $person): array
    {
        if ($person->id === null) {
            return [];
        }
        $clauses = [];
        $params = [(int) $person->id];
        if ($person->fatherId) {
            $clauses[] = 'p.`father_id` = ?';
            $params[] = $person->fatherId;
        }
        if ($person->motherId) {
            $clauses[] = 'p.`mother_id` = ?';
            $params[] = $person->motherId;
        }
        if ($clauses === []) {
            return [];
        }
        $rows = $this->db->query(
            $this->selectSql() . '
              WHERE p.`id` <> ? AND (' . implode(' OR ', $clauses) . ')
              ORDER BY p.`birth_date` IS NULL, p.`birth_date`, p.`name`',
            $params
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array{total:int,male:int,female:int} */
    public function counts(): array
    {
        $row = $this->db->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN `gender` = 'male' THEN 1 ELSE 0 END) AS male_cnt,
                    SUM(CASE WHEN `gender` = 'female' THEN 1 ELSE 0 END) AS female_cnt
               FROM `#__family_people`"
        )->row;
        return [
            'total'  => (int) ($row['total'] ?? 0),
            'male'   => (int) ($row['male_cnt'] ?? 0),
            'female' => (int) ($row['female_cnt'] ?? 0),
        ];
    }

    public function create(array $data): Person
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        $gender = $this->normalizeGender((string) ($data['gender'] ?? ''));
        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug = $this->uniqueSlug($this->normalizeSlug($rawSlug !== '' ? $rawSlug : $name));

        $fatherId = $this->nullableId($data['father_id'] ?? null);
        $motherId = $this->nullableId($data['mother_id'] ?? null);
        $this->assertParents($fatherId, $motherId, null);

        $marriageId = $this->resolveMarriageId($fatherId, $motherId, $data['marriage_id'] ?? null);

        $id = $this->db->insert('family_people', [
            'slug'        => $slug,
            'name'        => mb_substr($name, 0, 160),
            'gender'      => $gender,
            'birth_date'  => $this->normalizeDate($data['birth_date'] ?? null),
            'death_date'  => $this->normalizeDate($data['death_date'] ?? null),
            'father_id'   => $fatherId,
            'mother_id'   => $motherId,
            'marriage_id' => $marriageId,
            'notes'       => trim((string) ($data['notes'] ?? '')),
            'photo'       => $this->normalizePhoto($data['photo'] ?? null),
            'active'      => !empty($data['active']) || !array_key_exists('active', $data) ? 1 : 0,
        ]);

        return $this->find($id) ?? throw new RuntimeException('Failed to reload person.');
    }

    public function update(int $id, array $data): Person
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Person not found.');
        }

        $update = [];

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Name cannot be empty.');
            }
            $update['name'] = mb_substr($name, 0, 160);
        }
        if (isset($data['gender'])) {
            $update['gender'] = $this->normalizeGender((string) $data['gender']);
        }
        if (isset($data['slug'])) {
            $raw = trim((string) $data['slug']);
            $seed = $raw !== '' ? $raw : (string) ($update['name'] ?? $existing->name);
            $slug = $this->normalizeSlug($seed);
            $dupe = $this->findBySlug($slug);
            if ($dupe !== null && $dupe->id !== $id) {
                throw new RuntimeException("Slug '{$slug}' already exists.");
            }
            $update['slug'] = $slug;
        }
        if (array_key_exists('birth_date', $data)) {
            $update['birth_date'] = $this->normalizeDate($data['birth_date']);
        }
        if (array_key_exists('death_date', $data)) {
            $update['death_date'] = $this->normalizeDate($data['death_date']);
        }
        if (array_key_exists('notes', $data)) {
            $update['notes'] = trim((string) $data['notes']);
        }
        if (array_key_exists('photo', $data)) {
            $update['photo'] = $this->normalizePhoto($data['photo']);
        }
        if (array_key_exists('active', $data)) {
            $update['active'] = !empty($data['active']) ? 1 : 0;
        }

        $fatherId = array_key_exists('father_id', $data)
            ? $this->nullableId($data['father_id'])
            : $existing->fatherId;
        $motherId = array_key_exists('mother_id', $data)
            ? $this->nullableId($data['mother_id'])
            : $existing->motherId;

        if (array_key_exists('father_id', $data) || array_key_exists('mother_id', $data) || array_key_exists('marriage_id', $data)) {
            $this->assertParents($fatherId, $motherId, $id);
            $update['father_id'] = $fatherId;
            $update['mother_id'] = $motherId;
            $update['marriage_id'] = $this->resolveMarriageId(
                $fatherId,
                $motherId,
                $data['marriage_id'] ?? $existing->marriageId
            );
        }

        if ($update !== []) {
            $this->db->update('family_people', $update, ['id' => $id]);
        }

        return $this->find($id) ?? throw new RuntimeException('Failed to reload person.');
    }

    public function delete(int $id): void
    {
        $this->db->query(
            'UPDATE `#__family_people` SET `father_id` = NULL WHERE `father_id` = ?',
            [$id]
        );
        $this->db->query(
            'UPDATE `#__family_people` SET `mother_id` = NULL WHERE `mother_id` = ?',
            [$id]
        );
        $this->db->query('DELETE FROM `#__family_people` WHERE `id` = ?', [$id]);
    }

    private function selectSql(): string
    {
        return "SELECT p.*,
                       f.`name` AS father_name,
                       m.`name` AS mother_name
                  FROM `#__family_people` p
                  LEFT JOIN `#__family_people` f ON f.`id` = p.`father_id`
                  LEFT JOIN `#__family_people` m ON m.`id` = p.`mother_id`";
    }

    /**
     * @param array{q?:string,gender?:string,active?:string} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function buildSearchWhere(array $filters): array
    {
        $clauses = ['1=1'];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $clauses[] = '(p.`name` LIKE ? OR p.`slug` LIKE ? OR p.`notes` LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $gender = trim((string) ($filters['gender'] ?? ''));
        if (in_array($gender, ['male', 'female'], true)) {
            $clauses[] = 'p.`gender` = ?';
            $params[] = $gender;
        }

        if (array_key_exists('active', $filters) && $filters['active'] !== '' && $filters['active'] !== null) {
            $clauses[] = 'p.`active` = ?';
            $params[] = !empty($filters['active']) ? 1 : 0;
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    private function assertParents(?int $fatherId, ?int $motherId, ?int $selfId): void
    {
        if ($fatherId !== null) {
            if ($selfId !== null && $fatherId === $selfId) {
                throw new InvalidArgumentException('A person cannot be their own father.');
            }
            $father = $this->find($fatherId);
            if ($father === null || !$father->isMale()) {
                throw new InvalidArgumentException('Father must be an existing male person.');
            }
        }
        if ($motherId !== null) {
            if ($selfId !== null && $motherId === $selfId) {
                throw new InvalidArgumentException('A person cannot be their own mother.');
            }
            $mother = $this->find($motherId);
            if ($mother === null || !$mother->isFemale()) {
                throw new InvalidArgumentException('Mother must be an existing female person.');
            }
        }
    }

    private function resolveMarriageId(?int $fatherId, ?int $motherId, mixed $explicit): ?int
    {
        $explicitId = $this->nullableId($explicit);
        if ($explicitId !== null) {
            $row = $this->db->first('family_marriages', ['id' => $explicitId]);
            if ($row === []) {
                throw new InvalidArgumentException('Marriage not found.');
            }
            return $explicitId;
        }
        if ($fatherId === null || $motherId === null) {
            return null;
        }
        $row = $this->db->query(
            "SELECT `id` FROM `#__family_marriages`
              WHERE `husband_id` = ? AND `wife_id` = ?
              ORDER BY CASE `status` WHEN 'active' THEN 0 ELSE 1 END, `id` DESC
              LIMIT 1",
            [$fatherId, $motherId]
        )->row;
        return $row !== [] ? (int) $row['id'] : null;
    }

    private function hydrate(array $row): Person
    {
        $p = new Person();
        $p->id         = (int) $row['id'];
        $p->slug       = (string) $row['slug'];
        $p->name       = (string) $row['name'];
        $p->gender     = (string) $row['gender'];
        $p->birthDate  = $row['birth_date'] ?? null;
        $p->deathDate  = $row['death_date'] ?? null;
        $p->fatherId   = isset($row['father_id']) && $row['father_id'] !== null ? (int) $row['father_id'] : null;
        $p->motherId   = isset($row['mother_id']) && $row['mother_id'] !== null ? (int) $row['mother_id'] : null;
        $p->marriageId = isset($row['marriage_id']) && $row['marriage_id'] !== null ? (int) $row['marriage_id'] : null;
        $p->notes      = (string) ($row['notes'] ?? '');
        $p->photo      = isset($row['photo']) && $row['photo'] !== null && $row['photo'] !== ''
            ? (string) $row['photo']
            : null;
        $p->active     = (bool) ($row['active'] ?? true);
        $p->createdAt  = $row['created_at'] ?? null;
        $p->updatedAt  = $row['updated_at'] ?? null;
        $p->fatherName = isset($row['father_name']) ? (string) $row['father_name'] : null;
        $p->motherName = isset($row['mother_name']) ? (string) $row['mother_name'] : null;
        return $p;
    }

    private function normalizeGender(string $gender): string
    {
        $gender = strtolower(trim($gender));
        if (!in_array($gender, ['male', 'female'], true)) {
            throw new InvalidArgumentException('Gender must be male or female.');
        }
        return $gender;
    }

    private function normalizeSlug(string $slug): string
    {
        return slug($slug, '-');
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base !== '' ? $base : 'person';
        $candidate = $slug;
        $i = 2;
        while ($this->findBySlug($candidate) !== null) {
            $candidate = mb_substr($slug, 0, 110) . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    private function nullableId(mixed $value): ?int
    {
        $id = (int) $value;
        return $id > 0 ? $id : null;
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

    private function normalizePhoto(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('\\', '/', $value);
        if (!str_starts_with($value, 'uploads/') || str_contains($value, '..')) {
            throw new InvalidArgumentException('Invalid photo path.');
        }
        return mb_substr($value, 0, 255);
    }
}
