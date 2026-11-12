<?php
declare(strict_types=1);

namespace Module\Family\Services;

use System\Library\Database;

/**
 * Finds how two people are related via parents, children, and marriages.
 */
final class RelationshipService
{
    private PersonRepository $people;
    private MarriageRepository $marriages;

    public function __construct(private Database $db)
    {
        $this->people = new PersonRepository($db);
        $this->marriages = new MarriageRepository($db, $this->people);
    }

    /**
     * @return array{
     *   found: bool,
     *   from: ?Person,
     *   to: ?Person,
     *   steps: list<array{person:Person,via:string}>,
     *   label: string
     * }
     */
    public function find(int $fromId, int $toId): array
    {
        $from = $this->people->find($fromId);
        $to = $this->people->find($toId);

        $empty = [
            'found' => false,
            'from'  => $from,
            'to'    => $to,
            'steps' => [],
            'label' => '',
        ];

        if ($from === null || $to === null) {
            return $empty;
        }
        if ($fromId === $toId) {
            return [
                'found' => true,
                'from'  => $from,
                'to'    => $to,
                'steps' => [['person' => $from, 'via' => 'self']],
                'label' => 'Same person',
            ];
        }

        // BFS over undirected family graph (parents, children, spouses).
        $queue = [[$fromId]];
        $seen = [$fromId => true];
        $viaFrom = [$fromId => ['id' => $fromId, 'via' => 'start']];
        $foundPath = null;

        while ($queue !== []) {
            $path = array_shift($queue);
            $current = (int) end($path);
            if ($current === $toId) {
                $foundPath = $path;
                break;
            }
            if (count($path) > 12) {
                continue;
            }
            foreach ($this->neighbors($current) as $edge) {
                $nid = (int) $edge['id'];
                if (isset($seen[$nid])) {
                    continue;
                }
                $seen[$nid] = true;
                $viaFrom[$nid] = $edge;
                $queue[] = array_merge($path, [$nid]);
            }
        }

        if ($foundPath === null) {
            $empty['label'] = 'No relationship found within 12 steps.';
            return $empty;
        }

        $steps = [];
        foreach ($foundPath as $i => $pid) {
            $person = $this->people->find((int) $pid);
            if ($person === null) {
                continue;
            }
            $via = $i === 0 ? 'start' : (string) ($viaFrom[(int) $pid]['via'] ?? 'related');
            $steps[] = ['person' => $person, 'via' => $via];
        }

        return [
            'found' => true,
            'from'  => $from,
            'to'    => $to,
            'steps' => $steps,
            'label' => $this->labelForPath($steps),
        ];
    }

    /**
     * @return list<array{id:int,via:string}>
     */
    private function neighbors(int $personId): array
    {
        $person = $this->people->find($personId);
        if ($person === null) {
            return [];
        }

        $out = [];
        if ($person->fatherId) {
            $out[] = ['id' => (int) $person->fatherId, 'via' => 'father'];
        }
        if ($person->motherId) {
            $out[] = ['id' => (int) $person->motherId, 'via' => 'mother'];
        }
        foreach ($this->people->childrenOf($personId) as $child) {
            $out[] = ['id' => (int) $child->id, 'via' => 'child'];
        }
        foreach ($this->marriages->forPerson($personId) as $m) {
            $spouseId = $m->husbandId === $personId ? $m->wifeId : $m->husbandId;
            $out[] = ['id' => (int) $spouseId, 'via' => 'spouse'];
        }
        return $out;
    }

    /**
     * @param list<array{person:Person,via:string}> $steps
     */
    private function labelForPath(array $steps): string
    {
        $n = count($steps);
        if ($n <= 1) {
            return 'Same person';
        }
        $vias = [];
        for ($i = 1; $i < $n; $i++) {
            $vias[] = $steps[$i]['via'];
        }

        $key = implode('>', $vias);
        $simple = match ($key) {
            'father' => 'Father',
            'mother' => 'Mother',
            'child'  => 'Child',
            'spouse' => 'Spouse',
            'father>child', 'mother>child' => 'Sibling',
            'spouse>child' => 'Step-child / child of spouse',
            'child>spouse' => 'Child-in-law',
            'father>spouse', 'mother>spouse' => 'Parent-in-law',
            'father>father', 'mother>father' => 'Grandfather',
            'father>mother', 'mother>mother' => 'Grandmother',
            'child>child' => 'Grandchild',
            default => '',
        };
        if ($simple !== '') {
            return $simple;
        }

        // Cousin-ish / uncle patterns
        if ($n === 4 && ($vias[0] === 'father' || $vias[0] === 'mother')
            && ($vias[1] === 'father' || $vias[1] === 'mother')
            && $vias[2] === 'child') {
            return 'Uncle / aunt / cousin branch';
        }
        if ($n === 4 && $vias[0] === 'child'
            && ($vias[1] === 'father' || $vias[1] === 'mother')
            && $vias[2] === 'child') {
            return 'Niece / nephew / cousin branch';
        }

        return 'Related (' . ($n - 1) . ' steps)';
    }
}
