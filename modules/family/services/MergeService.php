<?php
declare(strict_types=1);

namespace Module\Family\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

/**
 * Merge duplicate people: keep one record, re-point links, delete the other.
 */
final class MergeService
{
    private PersonRepository $people;

    public function __construct(private Database $db)
    {
        $this->people = new PersonRepository($db);
    }

    /**
     * @return list<array{name:string,count:int,people:list<Person>}>
     */
    public function duplicateGroups(int $limit = 40): array
    {
        $rows = $this->db->query(
            "SELECT LOWER(TRIM(`name`)) AS nkey, COUNT(*) AS cnt
               FROM `#__family_people`
              GROUP BY nkey
             HAVING cnt > 1
              ORDER BY cnt DESC, nkey ASC
              LIMIT ?",
            [$limit]
        )->rows;

        $groups = [];
        foreach ($rows as $row) {
            $name = (string) ($row['nkey'] ?? '');
            if ($name === '') {
                continue;
            }
            $people = $this->people->search(['q' => $name], 50, 0, 'id', 'asc');
            // Keep only exact (case-insensitive) name matches from search.
            $people = array_values(array_filter(
                $people,
                static fn(Person $p): bool => mb_strtolower(trim($p->name)) === $name
            ));
            if (count($people) < 2) {
                continue;
            }
            $groups[] = [
                'name'   => $people[0]->name,
                'count'  => count($people),
                'people' => $people,
            ];
        }
        return $groups;
    }

    public function merge(int $keepId, int $removeId): Person
    {
        if ($keepId <= 0 || $removeId <= 0) {
            throw new InvalidArgumentException('Choose two people to merge.');
        }
        if ($keepId === $removeId) {
            throw new InvalidArgumentException('Cannot merge a person into themselves.');
        }

        $keep = $this->people->find($keepId);
        $remove = $this->people->find($removeId);
        if ($keep === null || $remove === null) {
            throw new RuntimeException('Person not found.');
        }
        if ($keep->gender !== $remove->gender) {
            throw new InvalidArgumentException('Cannot merge people with different genders.');
        }

        $this->db->beginTransaction();
        try {
            // Prefer filled fields from the removed person when keep is empty.
            $fill = [];
            if (($keep->birthDate === null || $keep->birthDate === '') && $remove->birthDate) {
                $fill['birth_date'] = $remove->birthDate;
            }
            if (($keep->deathDate === null || $keep->deathDate === '') && $remove->deathDate) {
                $fill['death_date'] = $remove->deathDate;
            }
            if (($keep->notes === '') && $remove->notes !== '') {
                $fill['notes'] = $remove->notes;
            }
            if (($keep->photo === null || $keep->photo === '') && $remove->photo) {
                $fill['photo'] = $remove->photo;
            }
            if ($keep->fatherId === null && $remove->fatherId && $remove->fatherId !== $keepId) {
                $fill['father_id'] = $remove->fatherId;
            }
            if ($keep->motherId === null && $remove->motherId && $remove->motherId !== $keepId) {
                $fill['mother_id'] = $remove->motherId;
            }
            if ($fill !== []) {
                $this->people->update($keepId, $fill);
            }

            // Re-point children / parent refs.
            $this->db->query(
                'UPDATE `#__family_people` SET `father_id` = ? WHERE `father_id` = ?',
                [$keepId, $removeId]
            );
            $this->db->query(
                'UPDATE `#__family_people` SET `mother_id` = ? WHERE `mother_id` = ?',
                [$keepId, $removeId]
            );

            // Clear self-parent if merge created a loop.
            $this->db->query(
                'UPDATE `#__family_people` SET `father_id` = NULL WHERE `id` = ? AND `father_id` = ?',
                [$keepId, $keepId]
            );
            $this->db->query(
                'UPDATE `#__family_people` SET `mother_id` = NULL WHERE `id` = ? AND `mother_id` = ?',
                [$keepId, $keepId]
            );

            $this->remapMarriages($keepId, $removeId);

            $adoptedPhoto = !empty($fill['photo']) && $fill['photo'] === $remove->photo;
            $orphanPhoto = $remove->photo && !$adoptedPhoto && $remove->photo !== ($keep->photo ?? null)
                ? $remove->photo
                : null;

            $this->people->delete($removeId);
            $this->db->commit();

            if ($orphanPhoto) {
                (new \System\Library\ImageUploader((string) BASEDIR))->delete($orphanPhoto);
            }

            return $this->people->find($keepId)
                ?? throw new RuntimeException('Failed to reload merged person.');
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function remapMarriages(int $keepId, int $removeId): void
    {
        $rows = $this->db->query(
            'SELECT * FROM `#__family_marriages`
              WHERE `husband_id` = ? OR `wife_id` = ?',
            [$removeId, $removeId]
        )->rows;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $husband = (int) $row['husband_id'] === $removeId ? $keepId : (int) $row['husband_id'];
            $wife = (int) $row['wife_id'] === $removeId ? $keepId : (int) $row['wife_id'];

            if ($husband === $wife) {
                $this->db->query('DELETE FROM `#__family_marriages` WHERE `id` = ?', [$id]);
                $this->db->query(
                    'UPDATE `#__family_people` SET `marriage_id` = NULL WHERE `marriage_id` = ?',
                    [$id]
                );
                continue;
            }

            // Duplicate couple with keep already?
            $dupe = $this->db->query(
                'SELECT `id` FROM `#__family_marriages`
                  WHERE `husband_id` = ? AND `wife_id` = ? AND `id` <> ?
                  LIMIT 1',
                [$husband, $wife, $id]
            )->row;
            if ($dupe !== []) {
                $keepMarriageId = (int) $dupe['id'];
                $this->db->query(
                    'UPDATE `#__family_people` SET `marriage_id` = ? WHERE `marriage_id` = ?',
                    [$keepMarriageId, $id]
                );
                $this->db->query('DELETE FROM `#__family_marriages` WHERE `id` = ?', [$id]);
                continue;
            }

            $this->db->query(
                'UPDATE `#__family_marriages` SET `husband_id` = ?, `wife_id` = ? WHERE `id` = ?',
                [$husband, $wife, $id]
            );
        }
    }
}
