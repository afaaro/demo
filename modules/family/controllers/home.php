<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/helpers/functions.php';

use Module\Family\Services\MarriageRepository;
use Module\Family\Services\Person;
use Module\Family\Services\PersonRepository;
use Module\Family\Services\RelationshipService;
use Module\Family\Services\TreeService;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Paginator;

class FamilyHome extends Controller
{
    private PersonRepository $people;
    private MarriageRepository $marriages;
    private TreeService $tree;
    private RelationshipService $relationships;

    protected function boot(): void
    {
        $this->people = new PersonRepository($this->db);
        $this->marriages = new MarriageRepository($this->db, $this->people);
        $this->tree = new TreeService($this->db);
        $this->relationships = new RelationshipService($this->db);
        $this->view->addModuleStyle('family', 'family.css');
    }

    public function indexAction(): void
    {
        $cfg = family_module_config();
        $page = max(1, (int) $this->request->get('page', 'int', 1));
        $perPage = $cfg['people_per_page'];
        $offset = ($page - 1) * $perPage;
        $q = trim((string) $this->request->get('q', 'string', ''));
        if (function_exists('mb_substr')) {
            $q = trim(mb_substr($q, 0, 120));
        }
        $gender = trim((string) $this->request->get('gender', 'string', ''));
        $filters = ['q' => $q, 'active' => '1'];
        if (in_array($gender, ['male', 'female'], true)) {
            $filters['gender'] = $gender;
        }

        $items = $this->people->search($filters, $perPage, $offset, 'name', 'asc');
        $total = $this->people->countSearch($filters);
        $pager = Paginator::make($total, $page, $perPage);

        echo $this->view->inline(function () use ($items, $pager, $q, $gender, $total) {
            $this->view->assign('title', $q !== '' ? 'Search · Family' : 'Family');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="fam"><div class="fam-head"><h1>Family</h1>';
            echo '<div class="fam-head__tools">';
            echo '<a class="fam-link" href="' . $e($this->url->to('family/relate')) . '">Relationship finder</a>';
            echo '<form class="fam-search" method="get" action="' . $e($this->url->to('family')) . '">';
            if ($gender !== '') {
                echo '<input type="hidden" name="gender" value="' . $e($gender) . '">';
            }
            echo '<input type="search" name="q" value="' . $e($q) . '" placeholder="Search people…" maxlength="120">';
            echo '<button type="submit">Search</button></form></div></div>';

            echo '<nav class="kb-domains fam-domains" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">';
            foreach (['' => 'All', 'male' => 'Men', 'female' => 'Women'] as $g => $label) {
                $params = array_filter(['q' => $q !== '' ? $q : null, 'gender' => $g !== '' ? $g : null]);
                $active = $gender === $g ? ' is-active' : '';
                echo '<a class="kb-domain' . $active . '" style="display:inline-flex;border:1px solid var(--border,#e5e7eb);border-radius:8px;padding:.28rem .7rem;text-decoration:none;font-size:.8rem;font-weight:600;background:' . ($gender === $g ? 'var(--brand,#2563eb)' : 'var(--bg-surface,#fff)') . ';color:' . ($gender === $g ? '#fff' : 'inherit') . '" href="'
                    . $e($this->url->to('family', $params)) . '">' . $e($label) . '</a>';
            }
            echo '</nav>';

            if ($items === []) {
                echo '<p class="fam-empty">' . ($q !== '' ? 'No people match “' . $e($q) . '”.' : 'No people yet.') . '</p></div>';
                return;
            }

            if ($q !== '') {
                echo '<p class="fam-card__meta" style="margin-bottom:.75rem">' . (int) $total . ' result'
                    . ((int) $total === 1 ? '' : 's') . '</p>';
            }

            echo '<div class="fam-grid">';
            foreach ($items as $p) {
                $photoUrl = family_photo_url($p->photo);
                echo '<a class="fam-card" href="' . $e($this->url->to('family/person', ['slug' => $p->slug])) . '">';
                if ($photoUrl !== '') {
                    echo '<img class="fam-card__photo" src="' . $e($photoUrl) . '" alt="">';
                }
                echo '<h2 class="fam-card__name">' . $e($p->name) . '</h2>';
                echo '<p class="fam-card__meta">' . $e(ucfirst($p->gender));
                if ($p->birthDate) {
                    echo ' · b. ' . $e($p->birthDate);
                }
                echo '</p></a>';
            }
            echo '</div>';

            $params = array_filter(['q' => $q !== '' ? $q : null, 'gender' => $gender !== '' ? $gender : null]);
            echo $pager->render(
                fn (int $pg) => $this->url->to('family', $params + ['page' => $pg]),
                ['theme' => 'bootstrap']
            );
            echo '</div>';
        });
    }

    public function personAction(): void
    {
        $slug = trim((string) $this->request->get('slug', 'string', ''));
        $person = $slug !== '' ? $this->people->findBySlug($slug) : null;
        if ($person === null || !$person->active) {
            throw new NotFoundException('Person not found.');
        }

        $marriages = $this->marriages->forPerson((int) $person->id);
        $children = $this->people->childrenOf((int) $person->id);
        $siblings = $this->people->siblingsOf($person);
        $father = $person->fatherId ? $this->people->find($person->fatherId) : null;
        $mother = $person->motherId ? $this->people->find($person->motherId) : null;
        $activeWives = $person->isMale() ? $this->marriages->countActiveWives((int) $person->id) : 0;
        $max = family_max_wives();

        echo $this->view->inline(function () use ($person, $marriages, $children, $siblings, $father, $mother, $activeWives, $max) {
            $this->view->assign('title', $person->name);
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            $photoUrl = family_photo_url($person->photo);
            echo '<div class="fam fam-profile"><div class="fam-head">';
            echo '<div class="fam-profile__intro">';
            if ($photoUrl !== '') {
                echo '<img class="fam-profile__photo" src="' . $e($photoUrl) . '" alt="">';
            }
            echo '<div><p class="fam-card__meta mb-1"><a href="' . $e($this->url->to('family')) . '">Family</a></p>';
            echo '<h1>' . $e($person->name) . '</h1>';
            echo '<p class="fam-card__meta">' . $e(ucfirst($person->gender));
            if ($person->birthDate) {
                echo ' · born ' . $e($person->birthDate);
            }
            if ($person->deathDate) {
                echo ' · died ' . $e($person->deathDate);
            }
            if ($person->isMale()) {
                echo ' · wives ' . (int) $activeWives . '/' . (int) $max;
            }
            echo '</p></div></div>';
            echo '<div class="fam-profile__actions">';
            echo '<a class="sf-btn" href="' . $e($this->url->to('family/relate', ['from' => $person->slug])) . '">Find relation</a>';
            echo '<a class="sf-btn is-primary" href="' . $e($this->url->to('family/tree', ['slug' => $person->slug])) . '">View tree</a>';
            echo '</div></div>';

            echo '<section class="fam-section"><h2>Parents</h2><div class="fam-list">';
            if ($father === null && $mother === null) {
                echo '<p class="fam-empty">No parents linked.</p>';
            } else {
                if ($father) {
                    echo '<a href="' . $e($this->url->to('family/person', ['slug' => $father->slug])) . '">Father: ' . $e($father->name) . '</a>';
                }
                if ($mother) {
                    echo '<a href="' . $e($this->url->to('family/person', ['slug' => $mother->slug])) . '">Mother: ' . $e($mother->name) . '</a>';
                }
            }
            echo '</div></section>';

            echo '<section class="fam-section"><h2>Spouses</h2><div class="fam-list">';
            if ($marriages === []) {
                echo '<p class="fam-empty">No marriages recorded.</p>';
            } else {
                foreach ($marriages as $m) {
                    $spouseName = $person->isMale() ? (string) $m->wifeName : (string) $m->husbandName;
                    $spouseSlug = $person->isMale() ? (string) $m->wifeSlug : (string) $m->husbandSlug;
                    $pill = match ($m->status) {
                        'active'   => '<span class="fam-pill is-ok">Active</span>',
                        'divorced' => '<span class="fam-pill is-off">Divorced</span>',
                        'widowed'  => '<span class="fam-pill is-warn">Widowed</span>',
                        default    => '',
                    };
                    echo '<a href="' . $e($this->url->to('family/person', ['slug' => $spouseSlug])) . '">'
                        . $e($spouseName) . ' ' . $pill . '</a>';
                }
            }
            echo '</div></section>';

            echo '<section class="fam-section"><h2>Children</h2><div class="fam-list">';
            if ($children === []) {
                echo '<p class="fam-empty">No children linked.</p>';
            } else {
                foreach ($children as $c) {
                    echo '<a href="' . $e($this->url->to('family/person', ['slug' => $c->slug])) . '">' . $e($c->name) . '</a>';
                }
            }
            echo '</div></section>';

            if ($siblings !== []) {
                echo '<section class="fam-section"><h2>Siblings</h2><div class="fam-list">';
                foreach ($siblings as $s) {
                    echo '<a href="' . $e($this->url->to('family/person', ['slug' => $s->slug])) . '">' . $e($s->name) . '</a>';
                }
                echo '</div></section>';
            }

            if (trim($person->notes) !== '') {
                echo '<section class="fam-section"><h2>Notes</h2><p>' . $e($person->notes) . '</p></section>';
            }
            echo '</div>';
        });
    }

    public function relateAction(): void
    {
        $fromSlug = trim((string) $this->request->get('from', 'string', ''));
        $toSlug = trim((string) $this->request->get('to', 'string', ''));
        $fromId = (int) $this->request->get('from_id', 'int', 0);
        $toId = (int) $this->request->get('to_id', 'int', 0);

        if ($fromSlug !== '' && $fromId <= 0) {
            $p = $this->people->findBySlug($fromSlug);
            $fromId = $p && $p->active ? (int) $p->id : 0;
        }
        if ($toSlug !== '' && $toId <= 0) {
            $p = $this->people->findBySlug($toSlug);
            $toId = $p && $p->active ? (int) $p->id : 0;
        }

        $result = null;
        if ($fromId > 0 && $toId > 0) {
            $result = $this->relationships->find($fromId, $toId);
        }

        $opts = ['' => '— Choose —'];
        foreach ($this->people->options(null, true) as $p) {
            $opts[$p->slug] = $p->name;
        }

        echo $this->view->inline(function () use ($fromSlug, $toSlug, $fromId, $toId, $result, $opts) {
            $this->view->assign('title', 'Relationship finder');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            $fromKey = $fromSlug !== '' ? $fromSlug : '';
            $toKey = $toSlug !== '' ? $toSlug : '';
            if ($fromKey === '' && $fromId > 0) {
                $fp = $this->people->find($fromId);
                $fromKey = $fp?->slug ?? '';
            }
            if ($toKey === '' && $toId > 0) {
                $tp = $this->people->find($toId);
                $toKey = $tp?->slug ?? '';
            }

            echo '<div class="fam"><div class="fam-head">';
            echo '<div><p class="fam-card__meta mb-1"><a href="' . $e($this->url->to('family')) . '">Family</a></p>';
            echo '<h1>Relationship finder</h1></div></div>';

            echo '<form class="fam-relate-form" method="get" action="' . $e($this->url->to('family/relate')) . '">';
            echo '<label>Person A<select name="from">';
            foreach ($opts as $slug => $label) {
                echo '<option value="' . $e($slug) . '"' . ($slug === $fromKey ? ' selected' : '') . '>'
                    . $e($label) . '</option>';
            }
            echo '</select></label>';
            echo '<label>Person B<select name="to">';
            foreach ($opts as $slug => $label) {
                echo '<option value="' . $e($slug) . '"' . ($slug === $toKey ? ' selected' : '') . '>'
                    . $e($label) . '</option>';
            }
            echo '</select></label>';
            echo '<button type="submit">Find</button></form>';

            if ($result !== null) {
                echo '<section class="fam-section"><h2>Result</h2>';
                if (!$result['found']) {
                    echo '<p class="fam-empty">' . $e($result['label'] !== '' ? $result['label'] : 'No relationship found.') . '</p>';
                } else {
                    echo '<p class="fam-relate-label">' . $e($result['label']) . '</p>';
                    echo '<ol class="fam-relate-path">';
                    foreach ($result['steps'] as $i => $step) {
                        $p = $step['person'];
                        $via = (string) $step['via'];
                        echo '<li>';
                        if ($i > 0) {
                            $viaText = match ($via) {
                                'father' => 'their father',
                                'mother' => 'their mother',
                                'child'  => 'their child',
                                'spouse' => 'their spouse',
                                default  => $via,
                            };
                            echo '<span class="fam-card__meta">' . $e($viaText) . '</span> ';
                        }
                        echo '<a href="' . $e($this->url->to('family/person', ['slug' => $p->slug])) . '">'
                            . $e($p->name) . '</a></li>';
                    }
                    echo '</ol>';
                }
                echo '</section>';
            }
            echo '</div>';
        });
    }

    public function treeAction(): void
    {
        $slug = trim((string) $this->request->get('slug', 'string', ''));
        $person = $slug !== '' ? $this->people->findBySlug($slug) : null;
        if ($person === null || !$person->active) {
            throw new NotFoundException('Person not found.');
        }

        $cfg = family_module_config();
        $depth = (int) $this->request->get('depth', 'int', $cfg['tree_default_depth']);
        if ($depth < 0) {
            $depth = 0;
        }
        $tree = $this->tree->build((int) $person->id, $depth);
        $depth = (int) ($tree['depth'] ?? $depth);

        $this->view->addModuleScript('family', 'family-tree.js');

        echo $this->view->inline(function () use ($person, $tree, $depth) {
            $this->view->assign('title', 'Tree · ' . $person->name);
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="fam"><div class="fam-head">';
            echo '<div><p class="fam-card__meta mb-1"><a href="' . $e($this->url->to('family/person', ['slug' => $person->slug])) . '">'
                . $e($person->name) . '</a></p><h1>Family tree</h1></div>';
            echo '<form method="get" action="' . $e($this->url->to('family/tree', ['slug' => $person->slug])) . '" class="fam-search">';
            echo '<label class="fam-card__meta">Depth <select name="depth" onchange="this.form.submit()">';
            echo '<option value="0"' . ($depth === 0 ? ' selected' : '') . '>All</option>';
            for ($d = 1; $d <= 20; $d++) {
                echo '<option value="' . $d . '"' . ($d === $depth ? ' selected' : '') . '>' . $d . '</option>';
            }
            echo '</select></label></form></div>';

            echo '<div class="fam-tree-wrap" data-fam-tree><div class="ft">';

            $father = is_array($tree['parents']['father'] ?? null) ? $tree['parents']['father'] : null;
            $mother = is_array($tree['parents']['mother'] ?? null) ? $tree['parents']['mother'] : null;
            if ($father !== null || $mother !== null) {
                echo '<div class="ft-gen ft-gen--parents">';
                echo '<div class="ft-union">';
                echo '<div class="ft-couple">';
                if ($father !== null) {
                    echo $this->renderAvatar($father);
                }
                if ($father !== null && $mother !== null) {
                    echo '<span class="ft-marriage" title="Parents"></span>';
                }
                if ($mother !== null) {
                    echo $this->renderAvatar($mother);
                }
                echo '</div>';
                echo '<div class="ft-stem"></div>';
                echo '</div></div>';
            }

            echo $this->renderTreeNode($tree['root']);

            echo '</div></div></div>';
        });
    }

    /**
     * One spouse column: wife/husband avatar, then her/his children under a stem.
     *
     * @param array<string,mixed> $spousePerson
     * @param list<array<string,mixed>> $childNodes
     */
    private function renderSpouseBranch(array $spousePerson, array $childNodes, string $status = 'active'): string
    {
        $muted = $status !== '' && $status !== 'active';
        $html = '<div class="ft-spouse-branch' . ($muted ? ' is-ended' : '') . '">';
        $html .= '<span class="ft-bracket__drop"></span>';
        $html .= $this->renderAvatar($spousePerson, false, $muted, $status);

        if ($childNodes !== []) {
            $html .= '<div class="ft-stem"></div>';
            $html .= '<div class="ft-bracket ft-bracket--kids">';
            foreach ($childNodes as $childNode) {
                if (!is_array($childNode)) {
                    continue;
                }
                $html .= '<div class="ft-bracket__slot">';
                $html .= '<span class="ft-bracket__drop"></span>';
                $html .= $this->renderTreeNode($childNode);
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /** @param array<string,mixed> $node */
    private function renderTreeNode(array $node): string
    {
        $person = is_array($node['person'] ?? null) ? $node['person'] : [];
        $spouses = is_array($node['spouses'] ?? null) ? $node['spouses'] : [];
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $isMale = (($person['gender'] ?? '') === 'male');

        // Group children by spouse id (wife for men, husband for women).
        $bySpouse = [];
        foreach ($children as $child) {
            if (!is_array($child) || !is_array($child['node'] ?? null)) {
                continue;
            }
            $spouseKey = $child['via_spouse_id'] ?? $child['via_wife_id'] ?? 0;
            $key = (string) ((int) $spouseKey);
            $bySpouse[$key][] = $child['node'];
        }

        $html = '<div class="ft-person-block">';
        $html .= $this->renderAvatar($person, !empty($person['is_root']));

        if ($spouses === []) {
            $orphanNodes = [];
            foreach ($bySpouse as $group) {
                foreach ($group as $n) {
                    $orphanNodes[] = $n;
                }
            }
            if ($orphanNodes !== []) {
                $html .= '<div class="ft-stem"></div><div class="ft-bracket ft-bracket--kids">';
                foreach ($orphanNodes as $childNode) {
                    $html .= '<div class="ft-bracket__slot"><span class="ft-bracket__drop"></span>';
                    $html .= $this->renderTreeNode($childNode);
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
            return $html . '</div>';
        }

        // One person → bracket of spouses; each spouse owns their children.
        $html .= '<div class="ft-stem"></div>';
        $html .= '<div class="ft-bracket ft-bracket--spouses">';

        foreach ($spouses as $spouse) {
            if (!is_array($spouse)) {
                continue;
            }
            $sp = is_array($spouse['person'] ?? null) ? $spouse['person'] : [];
            $status = (string) (($spouse['marriage']['status'] ?? '') ?: 'active');
            $spouseId = (int) ($sp['id'] ?? 0);
            $kids = $bySpouse[(string) $spouseId] ?? [];
            unset($bySpouse[(string) $spouseId]);
            $html .= $this->renderSpouseBranch($sp, $kids, $status);
        }

        if ($bySpouse !== []) {
            $orphanNodes = [];
            foreach ($bySpouse as $group) {
                foreach ($group as $n) {
                    $orphanNodes[] = $n;
                }
            }
            $unknownLabel = $isMale ? 'Unknown mother' : 'Unknown father';
            $html .= '<div class="ft-spouse-branch is-ended">';
            $html .= '<span class="ft-bracket__drop"></span>';
            $html .= '<div class="ft-avatar is-muted"><span class="ft-avatar__circle">?</span>'
                . '<span class="ft-avatar__name">' . $this->view->e($unknownLabel) . '</span></div>';
            if ($orphanNodes !== []) {
                $html .= '<div class="ft-stem"></div><div class="ft-bracket ft-bracket--kids">';
                foreach ($orphanNodes as $childNode) {
                    $html .= '<div class="ft-bracket__slot"><span class="ft-bracket__drop"></span>';
                    $html .= $this->renderTreeNode($childNode);
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    /** @param array<string,mixed> $person */
    private function renderAvatar(
        array $person,
        bool $isRoot = false,
        bool $muted = false,
        string $status = ''
    ): string {
        $e = fn(string|int|float $s): string => $this->view->e((string) $s);
        $slug = (string) ($person['slug'] ?? '');
        $name = (string) ($person['name'] ?? 'Unknown');
        $gender = (string) ($person['gender'] ?? '');
        $initials = $this->initials($name);
        $cls = 'ft-avatar';
        if ($isRoot) {
            $cls .= ' is-root';
        }
        if ($muted) {
            $cls .= ' is-muted';
        }
        if ($gender === 'female') {
            $cls .= ' is-female';
        } elseif ($gender === 'male') {
            $cls .= ' is-male';
        }

        $firstName = $this->firstName($name);
        $photoUrl = family_photo_url(isset($person['photo']) ? (string) $person['photo'] : null);
        $html = '<a class="' . $cls . '" href="' . $e($this->url->to('family/person', ['slug' => $slug])) . '" title="' . $e($name) . '">';
        if ($photoUrl !== '') {
            $html .= '<span class="ft-avatar__circle has-photo"><img src="' . $e($photoUrl) . '" alt=""></span>';
        } else {
            $html .= '<span class="ft-avatar__circle">' . $e($initials) . '</span>';
        }
        $html .= '<span class="ft-avatar__name">' . $e($firstName) . '</span>';
        $role = $gender !== '' ? ucfirst($gender) : '';
        if ($status !== '' && $status !== 'active') {
            $role = ($role !== '' ? $role . ' · ' : '') . $status;
        }
        if (!empty($person['deceased'])) {
            $role = ($role !== '' ? $role . ' · ' : '') . 'deceased';
        }
        if ($role !== '') {
            $html .= '<span class="ft-avatar__role">' . $e($role) . '</span>';
        }
        return $html . '</a>';
    }

    private function firstName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Unknown';
        }
        $parts = preg_split('/\s+/u', $name) ?: [];
        foreach ($parts as $part) {
            if ($part !== '') {
                return $part;
            }
        }
        return $name;
    }

    private function initials(string $name): string
    {
        $first = $this->firstName($name);
        $out = mb_strtoupper(mb_substr($first, 0, 1));
        return $out !== '' ? $out : '?';
    }
}
