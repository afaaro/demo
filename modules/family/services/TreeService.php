<?php
declare(strict_types=1);

namespace Module\Family\Services;

use System\Library\Database;

final class TreeService
{
    /** Safety cap when depth is unlimited (0). */
    private const MAX_DEPTH = 100;

    private PersonRepository $people;
    private MarriageRepository $marriages;

    /** @var array<int, true> */
    private array $expanding = [];

    public function __construct(private Database $db)
    {
        $this->people = new PersonRepository($db);
        $this->marriages = new MarriageRepository($db, $this->people);
    }

    /**
     * @param int $depth Generations downward. Use 0 for unlimited (all descendants).
     * @return array{
     *   root: array<string,mixed>,
     *   parents: array{father:?array<string,mixed>, mother:?array<string,mixed>},
     *   depth: int
     * }
     */
    public function build(int $rootId, int $depth = 3): array
    {
        $depth = $depth <= 0 ? 0 : max(1, min(self::MAX_DEPTH, $depth));
        $depthLeft = $depth === 0 ? self::MAX_DEPTH : $depth;
        $root = $this->people->find($rootId);
        if ($root === null) {
            throw new \RuntimeException('Person not found.');
        }

        $this->expanding = [];

        return [
            'root'    => $this->node($root, $depthLeft, true),
            'parents' => [
                'father' => $root->fatherId ? $this->personCard($this->people->find($root->fatherId)) : null,
                'mother' => $root->motherId ? $this->personCard($this->people->find($root->motherId)) : null,
            ],
            'depth'   => $depth,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function node(Person $person, int $depthLeft, bool $isRoot = false): array
    {
        $personId = (int) $person->id;
        $maxWives = function_exists('family_max_wives') ? family_max_wives() : 4;

        // Cycle guard — return the person without expanding again.
        if (isset($this->expanding[$personId])) {
            return [
                'person'       => $this->personCard($person) + ['is_root' => $isRoot],
                'spouses'      => [],
                'children'     => [],
                'active_wives' => $person->isMale() ? $this->marriages->countActiveWives($personId) : 0,
                'max_wives'    => $maxWives,
            ];
        }
        $this->expanding[$personId] = true;

        $marriages = $this->marriages->forPerson($personId);
        $spouses = [];
        $children = [];

        if ($person->isMale()) {
            $activeCount = 0;
            foreach ($marriages as $marriage) {
                if ($marriage->husbandId !== (int) $person->id) {
                    continue;
                }
                if ($marriage->isActive()) {
                    $activeCount++;
                }
                $wife = $this->people->find($marriage->wifeId);
                if ($wife === null) {
                    continue;
                }
                $spouses[] = [
                    'person'   => $this->personCard($wife),
                    'marriage' => [
                        'id'     => $marriage->id,
                        'status' => $marriage->status,
                    ],
                ];
                if ($depthLeft > 0) {
                    foreach ($this->people->childrenOfCouple((int) $person->id, $marriage->wifeId) as $child) {
                        $children[] = [
                            'via_spouse_id' => $marriage->wifeId,
                            'node'          => $this->node($child, $depthLeft - 1),
                        ];
                    }
                }
            }
            // Children with unknown/other mother
            if ($depthLeft > 0) {
                $known = [];
                foreach ($children as $c) {
                    $known[(int) ($c['node']['person']['id'] ?? 0)] = true;
                }
                foreach ($this->people->childrenOf((int) $person->id) as $child) {
                    if (isset($known[(int) $child->id])) {
                        continue;
                    }
                    if ($child->fatherId !== (int) $person->id) {
                        continue;
                    }
                    $children[] = [
                        'via_spouse_id' => $child->motherId,
                        'node'          => $this->node($child, $depthLeft - 1),
                    ];
                }
            }
        } else {
            // Female root: show husbands + children under each husband
            foreach ($marriages as $marriage) {
                if ($marriage->wifeId !== (int) $person->id) {
                    continue;
                }
                $husband = $this->people->find($marriage->husbandId);
                if ($husband === null) {
                    continue;
                }
                $spouses[] = [
                    'person'   => $this->personCard($husband),
                    'marriage' => [
                        'id'     => $marriage->id,
                        'status' => $marriage->status,
                    ],
                ];
                if ($depthLeft > 0) {
                    foreach ($this->people->childrenOfCouple($marriage->husbandId, (int) $person->id) as $child) {
                        $children[] = [
                            'via_spouse_id' => $marriage->husbandId,
                            'node'          => $this->node($child, $depthLeft - 1),
                        ];
                    }
                }
            }
            // Children with unknown/other father
            if ($depthLeft > 0) {
                $known = [];
                foreach ($children as $c) {
                    $known[(int) ($c['node']['person']['id'] ?? 0)] = true;
                }
                foreach ($this->people->childrenOf((int) $person->id) as $child) {
                    if (isset($known[(int) $child->id])) {
                        continue;
                    }
                    if ($child->motherId !== (int) $person->id) {
                        continue;
                    }
                    $children[] = [
                        'via_spouse_id' => $child->fatherId,
                        'node'          => $this->node($child, $depthLeft - 1),
                    ];
                }
            }
            $activeCount = 0;
            foreach ($spouses as $s) {
                if (($s['marriage']['status'] ?? '') === 'active') {
                    $activeCount++;
                }
            }
        }

        unset($this->expanding[$personId]);

        return [
            'person'       => $this->personCard($person) + ['is_root' => $isRoot],
            'spouses'      => $spouses,
            'children'     => $children,
            'active_wives' => $person->isMale() ? $this->marriages->countActiveWives($personId) : $activeCount,
            'max_wives'    => $maxWives,
        ];
    }

    /** @return array<string,mixed>|null */
    private function personCard(?Person $person): ?array
    {
        if ($person === null) {
            return null;
        }
        return [
            'id'         => $person->id,
            'slug'       => $person->slug,
            'name'       => $person->name,
            'gender'     => $person->gender,
            'birth_date' => $person->birthDate,
            'death_date' => $person->deathDate,
            'deceased'   => $person->isDeceased(),
            'photo'      => $person->photo,
        ];
    }
}
