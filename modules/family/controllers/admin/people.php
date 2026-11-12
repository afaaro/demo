<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Family\Services\MarriageRepository;
use Module\Family\Services\Person;
use Module\Family\Services\PersonRepository;
use System\Engine\Controller;
use System\Engine\NotFoundException;
use System\Library\Dataset;
use System\Library\ImageUploader;

class FamilyAdminPeople extends Controller
{
    private PersonRepository $people;
    private MarriageRepository $marriages;
    private ImageUploader $uploader;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->people = new PersonRepository($this->db);
        $this->marriages = new MarriageRepository($this->db, $this->people);
        $this->uploader = new ImageUploader((string) BASEDIR);
        $this->view->addModuleStyle('family', 'family.css');
    }

    public function indexAction(): void
    {
        $counts = $this->people->counts();

        $dataset = (new Dataset($this->registry))
            ->perPage(25)
            ->searchPlaceholder('Search name, slug, or notes…')
            ->sortable(['name', 'gender', 'birth_date', 'created_at'])
            ->defaultSort('name', 'asc')
            ->filter('gender', 'select', [
                ''       => 'All genders',
                'male'   => 'Male',
                'female' => 'Female',
            ])
            ->filter('active', 'select', [
                ''  => 'All statuses',
                '1' => 'Active',
                '0' => 'Inactive',
            ])
            ->columns([
                'person'     => 'Person',
                'gender'     => 'Gender',
                'parents'    => 'Parents',
                'dates'      => 'Dates',
                'status'     => 'Status',
                'actions'    => '',
            ])
            ->query(function ($db, array $state): array {
                $filters = [
                    'q'      => $state['q'],
                    'gender' => $state['filters']['gender'] ?? '',
                ];
                $active = $state['filters']['active'] ?? '';
                if ($active === '0' || $active === '1') {
                    $filters['active'] = $active;
                }
                $items = $this->people->search($filters, $state['per_page'], $state['offset'], $state['sort'], $state['dir']);
                return [
                    'rows'  => array_map(static fn(Person $p): array => ['_p' => $p], $items),
                    'total' => $this->people->countSearch($filters),
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'person' => function (array $row) use ($e): string {
                $p = $row['_p'];
                $photoUrl = family_photo_url($p->photo);
                $initial = mb_strtoupper(mb_substr(trim($p->name), 0, 1));
                $thumb = $photoUrl !== ''
                    ? '<img class="fam-admin-thumb" src="' . $e($photoUrl) . '" alt="">'
                    : '<span class="fam-admin-thumb is-empty is-' . $e($p->gender) . '">' . $e($initial !== '' ? $initial : '?') . '</span>';
                return '<div class="fam-admin-person">'
                    . $thumb
                    . '<div class="fam-admin-person__meta">'
                    . '<a class="sf-title" href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->id])) . '">'
                    . $e($p->name) . '</a>'
                    . '<span class="sf-sub">#' . (int) $p->id . ' · ' . $e($p->slug) . '</span>'
                    . '</div></div>';
            },
            'gender' => function (array $row): string {
                $g = $row['_p']->gender;
                $cls = $g === 'female' ? 'is-female' : 'is-male';
                return '<span class="fam-admin-pill ' . $cls . '">' . htmlspecialchars(ucfirst($g), ENT_QUOTES, 'UTF-8') . '</span>';
            },
            'parents' => function (array $row) use ($e): string {
                $p = $row['_p'];
                $bits = [];
                if ($p->fatherId && $p->fatherName) {
                    $bits[] = '<a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->fatherId])) . '">'
                        . $e($p->fatherName) . '</a>';
                }
                if ($p->motherId && $p->motherName) {
                    $bits[] = '<a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->motherId])) . '">'
                        . $e($p->motherName) . '</a>';
                }
                return $bits !== [] ? '<div class="fam-admin-parents">' . implode('<span class="sf-sub"> · </span>', $bits) . '</div>' : '<span class="sf-sub">—</span>';
            },
            'dates' => function (array $row) use ($e): string {
                $p = $row['_p'];
                $born = $p->birthDate ? 'b. ' . $p->birthDate : '';
                $died = $p->deathDate ? 'd. ' . $p->deathDate : '';
                $text = trim($born . ($born && $died ? ' · ' : '') . $died);
                return $text !== '' ? $e($text) : '<span class="sf-sub">—</span>';
            },
            'status' => function (array $row): string {
                $p = $row['_p'];
                if ($p->isDeceased()) {
                    return '<span class="sf-pill is-wait">Deceased</span>';
                }
                return $p->active
                    ? '<span class="sf-pill is-ok">Active</span>'
                    : '<span class="sf-pill is-off">Inactive</span>';
            },
            'actions' => function (array $row) use ($e): string {
                $p = $row['_p'];
                $html = '<div class="sf-row-acts">';
                $html .= '<a class="sf-btn" target="_blank" rel="noopener" href="'
                    . $e($this->url->to('family/tree', ['slug' => $p->slug])) . '" title="Tree"><i class="bi bi-diagram-3"></i></a>';
                $html .= '<a class="sf-btn" href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->id])) . '">Edit</a>';
                $html .= '<form method="post" action="' . $e($this->url->to('family/admin/people/delete'))
                    . '" onsubmit="return confirm(\'Delete this person?\');">';
                $html .= $this->request->csrfField();
                $html .= '<input type="hidden" name="id" value="' . (int) $p->id . '">';
                $html .= '<button type="submit" class="sf-btn is-danger" title="Delete"><i class="bi bi-trash"></i></button></form></div>';
                return $html;
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e, $counts) {
            $this->view->assign('title', 'Family people');
            $this->view->block('right_sidebar', 'Family');
            echo '<div class="sf-page"><div class="sf-head">';
            echo '<div><h1>People</h1><p>Manage family members, parents, and photos.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('family/admin/tools/merge')) . '" class="sf-btn"><i class="bi bi-intersect"></i>Merge</a>';
            echo '<a href="' . $e($this->url->to('family')) . '" class="sf-btn" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>View site</a>';
            echo '<a href="' . $e($this->url->to('family/admin/people/add')) . '" class="sf-btn is-primary"><i class="bi bi-plus-lg"></i>New person</a>';
            echo '</div></div>';

            echo '<div class="sf-stats fam-admin-stats">';
            foreach ([
                ['Total', $counts['total'], $this->url->to('family/admin/people')],
                ['Men', $counts['male'], $this->url->to('family/admin/people', ['gender' => 'male'])],
                ['Women', $counts['female'], $this->url->to('family/admin/people', ['gender' => 'female'])],
            ] as [$label, $value, $url]) {
                echo '<a href="' . $e($url) . '" class="sf-stat"><span>' . $e($label) . '</span><strong>' . (int) $value . '</strong></a>';
            }
            echo '</div>';

            echo '<section class="sf-card">' . $dataset->render() . '</section></div>';
        });
    }

    public function addAction(): void
    {
        $old = $this->emptyForm();
        // Prefill from query (e.g. Add child from a parent page).
        if (!$this->request->isPost()) {
            $fatherId = (int) $this->request->get('father_id', 'int', 0);
            $motherId = (int) $this->request->get('mother_id', 'int', 0);
            $gender = trim((string) $this->request->get('gender', 'string', ''));
            if ($fatherId > 0) {
                $old['father_id'] = $fatherId;
            }
            if ($motherId > 0) {
                $old['mother_id'] = $motherId;
            }
            if (in_array($gender, ['male', 'female'], true)) {
                $old['gender'] = $gender;
            }
        }

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $old = $this->readForm();
            try {
                $photo = $this->uploader->save($this->request->file('photo'));
                if ($photo !== null) {
                    $old['photo'] = $photo;
                }
                $person = $this->people->create($old);
                $this->notify->success('Person created.');
                redirect_to('family/admin/people/edit', ['id' => (int) $person->id]);
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
            }
        }

        echo $this->view->inline(function () use ($old) {
            $this->view->assign('title', 'New person');
            $this->view->block('right_sidebar', 'Family');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            echo '<div class="sf-page"><div class="sf-head"><div><h1>New person</h1><p>Given name first — full name fills when a father is selected.</p></div>';
            echo '<div class="sf-actions"><a class="sf-btn" href="' . $e($this->url->to('family/admin/people')) . '"><i class="bi bi-arrow-left"></i>Back</a></div></div>';
            echo '<section class="sf-card"><div class="sf-form">'
                . $this->personForm($old, $this->url->to('family/admin/people/add'), 'Create')
                . '</div></section></div>';
        });
    }

    public function editAction(): void
    {
        $id = (int) $this->request->get('id', 'int', 0);
        $person = $this->people->find($id);
        if ($person === null) {
            throw new NotFoundException('Person not found.');
        }
        $old = $this->itemToForm($person);

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $old = $this->readForm();
            try {
                $payload = $old;
                $removePhoto = (int) $this->request->post('remove_photo', 'int', 0) === 1;
                $newPhoto = $this->uploader->save($this->request->file('photo'));
                if ($newPhoto !== null) {
                    if ($person->photo) {
                        $this->uploader->delete($person->photo);
                    }
                    $payload['photo'] = $newPhoto;
                    $old['photo'] = $newPhoto;
                } elseif ($removePhoto) {
                    if ($person->photo) {
                        $this->uploader->delete($person->photo);
                    }
                    $payload['photo'] = null;
                    $old['photo'] = '';
                }
                $person = $this->people->update($id, $payload);
                $this->notify->success('Person updated.');
                redirect_to('family/admin/people/edit', ['id' => $id]);
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
            }
        }

        // Reload after possible failed POST so related panels stay accurate.
        $person = $this->people->find($id) ?? $person;
        $marriages = $this->marriages->forPerson($id);
        $children = $this->people->childrenOf($id);
        $siblings = $this->people->siblingsOf($person);
        $activeWives = $person->isMale() ? $this->marriages->countActiveWives($id) : 0;
        $father = $person->fatherId ? $this->people->find((int) $person->fatherId) : null;
        $mother = $person->motherId ? $this->people->find((int) $person->motherId) : null;

        echo $this->view->inline(function () use (
            $old, $id, $person, $marriages, $children, $siblings, $activeWives, $father, $mother
        ) {
            $this->view->assign('title', 'Edit · ' . $person->name);
            $this->view->block('right_sidebar', 'Family');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $max = family_max_wives();
            $photoUrl = family_photo_url($person->photo);
            $genderCls = $person->isFemale() ? 'is-female' : 'is-male';

            echo '<div class="sf-page">';
            echo '<div class="sf-head fam-admin-edit-head">';
            echo '<div class="fam-admin-edit-intro">';
            if ($photoUrl !== '') {
                echo '<img class="fam-admin-edit-photo" src="' . $e($photoUrl) . '" alt="">';
            } else {
                $initial = mb_strtoupper(mb_substr(trim($person->name), 0, 1));
                echo '<span class="fam-admin-edit-photo is-empty ' . $genderCls . '">' . $e($initial !== '' ? $initial : '?') . '</span>';
            }
            echo '<div>';
            echo '<h1>' . $e($person->name) . '</h1>';
            echo '<p class="fam-admin-edit-meta">';
            echo '<span class="fam-admin-pill ' . $genderCls . '">' . $e(ucfirst($person->gender)) . '</span>';
            if ($person->isDeceased()) {
                echo ' <span class="sf-pill is-wait">Deceased</span>';
            } elseif (!$person->active) {
                echo ' <span class="sf-pill is-off">Inactive</span>';
            }
            if ($person->birthDate) {
                echo ' <span class="sf-sub">b. ' . $e($person->birthDate) . '</span>';
            }
            if ($person->deathDate) {
                echo ' <span class="sf-sub">d. ' . $e($person->deathDate) . '</span>';
            }
            if ($person->isMale() && $activeWives > 0) {
                echo ' <span class="sf-sub">· ' . (int) $activeWives . '/' . (int) $max . ' active wives</span>';
            }
            echo '</p>';
            echo '<p class="fam-admin-edit-parents sf-sub">';
            if ($father) {
                echo 'Father: <a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $father->id])) . '">' . $e($father->name) . '</a>';
            } else {
                echo 'Father: —';
            }
            echo ' · ';
            if ($mother) {
                echo 'Mother: <a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $mother->id])) . '">' . $e($mother->name) . '</a>';
            } else {
                echo 'Mother: —';
            }
            echo '</p></div></div>';
            echo '<div class="sf-actions">';
            echo '<a class="sf-btn" href="' . $e($this->url->to('family/admin/people')) . '"><i class="bi bi-arrow-left"></i>Back</a>';
            echo '<a class="sf-btn" target="_blank" rel="noopener" href="' . $e($this->url->to('family/person', ['slug' => $person->slug])) . '">Profile</a>';
            echo '<a class="sf-btn" target="_blank" rel="noopener" href="' . $e($this->url->to('family/tree', ['slug' => $person->slug])) . '">Tree</a>';
            echo '</div></div>';

            echo '<div class="fam-admin-edit-grid">';
            echo '<section class="sf-card"><div class="sf-card-h"><h2>Details</h2></div><div class="sf-form">'
                . $this->personForm($old, $this->url->to('family/admin/people/edit', ['id' => $id]), 'Save')
                . '</div></section>';

            echo '<div class="fam-admin-edit-side">';
            echo '<section class="sf-card"><div class="sf-card-h"><h2>Marriages</h2>';
            echo '<a href="' . $e($this->url->to('family/admin/marriages/add', [
                'husband_id' => $person->isMale() ? $id : 0,
                'wife_id'    => $person->isFemale() ? $id : 0,
            ])) . '">Add</a></div>';
            if ($marriages === []) {
                echo '<p class="sf-empty">No marriages recorded.</p>';
            } else {
                echo '<div class="table-responsive"><table class="sf-table"><thead><tr><th>Spouse</th><th>Status</th><th>Dates</th><th></th></tr></thead><tbody>';
                foreach ($marriages as $m) {
                    $spouseId = $person->isMale() ? (int) $m->wifeId : (int) $m->husbandId;
                    $spouse = $person->isMale() ? ($m->wifeName ?? '') : ($m->husbandName ?? '');
                    $spouseCell = $spouseId > 0
                        ? '<a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $spouseId])) . '">' . $e($spouse) . '</a>'
                        : $e($spouse);
                    $statusPill = match ($m->status) {
                        'active'   => '<span class="sf-pill is-ok">Active</span>',
                        'divorced' => '<span class="sf-pill is-off">Divorced</span>',
                        'widowed'  => '<span class="sf-pill is-wait">Widowed</span>',
                        default    => $e(ucfirst($m->status)),
                    };
                    echo '<tr><td>' . $spouseCell . '</td><td>' . $statusPill . '</td>';
                    echo '<td class="sf-sub">' . $e(($m->marriedOn ?? '—') . ($m->endedOn ? ' → ' . $m->endedOn : '')) . '</td>';
                    echo '<td class="c-acts"><div class="sf-row-acts">';
                    if ($m->isActive()) {
                        echo '<form method="post" action="' . $e($this->url->to('family/admin/marriages/divorce'))
                            . '" onsubmit="return confirm(\'Mark this marriage as divorced?\');">';
                        echo $this->request->csrfField();
                        echo '<input type="hidden" name="id" value="' . (int) $m->id . '">';
                        echo '<input type="hidden" name="return_person" value="' . (int) $id . '">';
                        echo '<button type="submit" class="sf-btn">Divorce</button></form>';
                        echo '<form method="post" action="' . $e($this->url->to('family/admin/marriages/widow'))
                            . '" onsubmit="return confirm(\'Mark this marriage as widowed?\');">';
                        echo $this->request->csrfField();
                        echo '<input type="hidden" name="id" value="' . (int) $m->id . '">';
                        echo '<input type="hidden" name="return_person" value="' . (int) $id . '">';
                        echo '<button type="submit" class="sf-btn">Widowed</button></form>';
                    }
                    echo '<form method="post" action="' . $e($this->url->to('family/admin/marriages/delete'))
                        . '" onsubmit="return confirm(\'Delete this marriage record?\');">';
                    echo $this->request->csrfField();
                    echo '<input type="hidden" name="id" value="' . (int) $m->id . '">';
                    echo '<input type="hidden" name="return_person" value="' . (int) $id . '">';
                    echo '<button type="submit" class="sf-btn is-danger">Delete</button></form>';
                    echo '</div></td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';

            $addChildParams = $person->isMale()
                ? ['father_id' => $id]
                : ['mother_id' => $id];
            echo '<section class="sf-card"><div class="sf-card-h"><h2>Children <span class="sf-sub">(' . count($children) . ')</span></h2>';
            echo '<a href="' . $e($this->url->to('family/admin/people/add', $addChildParams)) . '">Add</a></div>';
            if ($children === []) {
                echo '<p class="sf-empty">No children linked.</p>';
            } else {
                echo '<div class="table-responsive"><table class="sf-table"><thead><tr><th>Name</th><th>Gender</th><th>Born</th></tr></thead><tbody>';
                foreach ($children as $c) {
                    $gCls = $c->isFemale() ? 'is-female' : 'is-male';
                    echo '<tr><td><a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $c->id])) . '">' . $e($c->name) . '</a></td>';
                    echo '<td><span class="fam-admin-pill ' . $gCls . '">' . $e(ucfirst($c->gender)) . '</span></td>';
                    echo '<td class="sf-sub">' . $e($c->birthDate ?? '—') . '</td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section>';

            if ($siblings !== []) {
                echo '<section class="sf-card"><div class="sf-card-h"><h2>Siblings <span class="sf-sub">(' . count($siblings) . ')</span></h2></div>';
                echo '<ul class="fam-admin-link-list">';
                foreach ($siblings as $s) {
                    echo '<li><a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $s->id])) . '">' . $e($s->name) . '</a>';
                    echo ' <span class="fam-admin-pill ' . ($s->isFemale() ? 'is-female' : 'is-male') . '">' . $e(ucfirst($s->gender)) . '</span></li>';
                }
                echo '</ul></section>';
            }

            echo '</div></div></div>';
        });
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            redirect_to('family/admin/people');
            return;
        }
        $this->guardCsrf();
        $id = (int) $this->request->post('id', 'int', 0);
        $person = $this->people->find($id);
        if ($person !== null) {
            $photo = $person->photo;
            $this->people->delete($id);
            if ($photo) {
                $this->uploader->delete($photo);
            }
            $this->notify->success("Deleted '{$person->name}'.");
        } else {
            $this->notify->error('Person not found.');
        }
        redirect_to('family/admin/people');
    }

    /**
     * AJAX quick-create for father/mother (and similar) pickers.
     */
    public function quickAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (!$this->request->isPost()) {
            http_response_code(405);
            echo json_encode(['ok' => false, 'message' => 'POST required.']);
            return;
        }

        try {
            $this->request->checkToken();
            $this->request->enforceRateLimit('family.people.quick', 30, 60);

            $name = trim((string) $this->request->post('name', 'string', ''));
            $gender = trim((string) $this->request->post('gender', 'string', ''));
            if ($name === '') {
                throw new \InvalidArgumentException('Name is required.');
            }
            if (!in_array($gender, ['male', 'female'], true)) {
                throw new \InvalidArgumentException('Gender must be male or female.');
            }

            $person = $this->people->create([
                'name'   => $name,
                'gender' => $gender,
                'active' => 1,
            ]);

            echo json_encode([
                'ok'     => true,
                'person' => [
                    'id'     => (int) $person->id,
                    'name'   => $person->name,
                    'gender' => $person->gender,
                    'slug'   => $person->slug,
                ],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\RuntimeException $e) {
            $code = (int) $e->getCode();
            http_response_code($code === 419 ? 403 : 400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            redirect_to('family/admin/people');
            exit;
        }
    }

    private function emptyForm(): array
    {
        return [
            'name'        => '',
            'slug'        => '',
            'gender'      => 'male',
            'birth_date'  => '',
            'death_date'  => '',
            'father_id'   => 0,
            'mother_id'   => 0,
            'marriage_id' => 0,
            'notes'       => '',
            'photo'       => '',
            'active'      => 1,
        ];
    }

    private function itemToForm(Person $p): array
    {
        return [
            'name'        => $p->name,
            'slug'        => $p->slug,
            'gender'      => $p->gender,
            'birth_date'  => $p->birthDate ?? '',
            'death_date'  => $p->deathDate ?? '',
            'father_id'   => (int) ($p->fatherId ?? 0),
            'mother_id'   => (int) ($p->motherId ?? 0),
            'marriage_id' => (int) ($p->marriageId ?? 0),
            'notes'       => $p->notes,
            'photo'       => $p->photo ?? '',
            'active'      => $p->active ? 1 : 0,
        ];
    }

    private function readForm(): array
    {
        return [
            'name'        => trim((string) $this->request->post('name', 'string', '')),
            'slug'        => trim((string) $this->request->post('slug', 'string', '')),
            'gender'      => trim((string) $this->request->post('gender', 'string', 'male')),
            'birth_date'  => trim((string) $this->request->post('birth_date', 'string', '')),
            'death_date'  => trim((string) $this->request->post('death_date', 'string', '')),
            'father_id'   => (int) $this->request->post('father_id', 'int', 0),
            'mother_id'   => (int) $this->request->post('mother_id', 'int', 0),
            'marriage_id' => (int) $this->request->post('marriage_id', 'int', 0),
            'notes'       => trim((string) $this->request->post('notes', 'raw', '')),
            'active'      => (int) $this->request->post('active', 'int', 0),
        ];
    }

    private function personForm(array $old, string $action, string $submitLabel): string
    {
        $fathers = $this->people->options('male', false);
        $mothers = $this->people->options('female', false);
        $fatherOpts = ['0' => '— None —'];
        foreach ($fathers as $p) {
            $fatherOpts[(string) $p->id] = $p->name;
        }
        $fatherOpts['__new__'] = '+ Add new father…';
        $motherOpts = ['0' => '— None —'];
        foreach ($mothers as $p) {
            $motherOpts[(string) $p->id] = $p->name;
        }
        $motherOpts['__new__'] = '+ Add new mother…';

        $quickUrl = $this->url->to('family/admin/people/quick');
        $csrf = $this->request->csrfField();

        ob_start();
        echo $this->form->open(['action' => $action, 'method' => 'POST', 'files' => true]);
        echo $this->form->row(['fields' => [
            [
                'type'     => 'text',
                'name'     => 'name',
                'id'       => 'field-name',
                'label'    => 'Name',
                'value'    => (string) $old['name'],
                'required' => true,
                'col'      => 'col-md-6',
                'help'     => 'Type the given name; full name is filled when a father is selected.',
            ],
            [
                'type' => 'text', 'name' => 'slug', 'label' => 'Slug', 'value' => (string) $old['slug'],
                'help' => 'Leave blank to auto-generate.', 'col' => 'col-md-6',
            ],
        ]]);
        $photoUrl = family_photo_url(isset($old['photo']) ? (string) $old['photo'] : null);
        echo '<div class="mb-3">';
        echo '<label class="form-label">Photo</label>';
        if ($photoUrl !== '') {
            echo '<div class="mb-2"><img class="fam-photo-preview" src="' . $this->view->e($photoUrl) . '" alt=""></div>';
            echo '<div class="form-check mb-2"><input type="checkbox" class="form-check-input" name="remove_photo" value="1" id="remove_photo">';
            echo '<label class="form-check-label" for="remove_photo">Remove current photo</label></div>';
        }
        echo '<input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">';
        echo '<p class="form-text mb-0">JPG, PNG, WEBP or GIF · max 5 MB.</p></div>';
        echo $this->form->row(['fields' => [
            [
                'type' => 'select', 'name' => 'gender', 'label' => 'Gender',
                'options' => ['male' => 'Male', 'female' => 'Female'],
                'value' => (string) $old['gender'], 'col' => 'col-md-4',
            ],
            [
                'type' => 'date', 'name' => 'birth_date', 'label' => 'Birth date',
                'value' => (string) $old['birth_date'], 'col' => 'col-md-4',
            ],
            [
                'type' => 'date', 'name' => 'death_date', 'label' => 'Death date',
                'value' => (string) $old['death_date'], 'col' => 'col-md-4',
            ],
        ]]);
        echo '<div class="row g-3 mb-3">';
        echo '<div class="col-md-6">';
        echo $this->form->field([
            'type'              => 'select',
            'name'              => 'father_id',
            'id'                => 'field-father_id',
            'label'             => 'Father',
            'options'           => $fatherOpts,
            'value'             => (string) ((int) $old['father_id']),
            'data-family-quick' => 'male',
        ]);
        echo '<p class="form-text mb-0">Missing someone? Choose “Add new father…”.</p>';
        echo '</div><div class="col-md-6">';
        echo $this->form->field([
            'type'              => 'select',
            'name'              => 'mother_id',
            'id'                => 'field-mother_id',
            'label'             => 'Mother',
            'options'           => $motherOpts,
            'value'             => (string) ((int) $old['mother_id']),
            'data-family-quick' => 'female',
        ]);
        echo '<p class="form-text mb-0">Missing someone? Choose “Add new mother…”.</p>';
        echo '</div></div>';
        echo $this->form->field([
            'type' => 'textarea', 'name' => 'notes', 'label' => 'Notes',
            'value' => (string) $old['notes'], 'rows' => 4,
        ]);
        echo '<div class="form-check mb-3">';
        echo '<input type="checkbox" class="form-check-input" name="active" value="1" id="person_active"'
            . (!empty($old['active']) ? ' checked' : '') . '>';
        echo '<label class="form-check-label" for="person_active">Active</label></div>';
        echo '<div class="sf-actions mt-4">';
        echo '<button type="submit" class="sf-btn is-primary">' . $this->view->e($submitLabel) . '</button>';
        echo '<a class="sf-btn" href="' . $this->view->e($this->url->to('family/admin/people')) . '">Cancel</a>';
        echo '</div>' . $this->form->close();

        // Hidden CSRF for AJAX (form already has one; this keeps token available to JS).
        echo '<div id="family-quick-csrf" class="d-none">' . $csrf . '</div>';
        echo $this->quickCreateScript($quickUrl);

        return (string) ob_get_clean();
    }

    private function quickCreateScript(string $quickUrl): string
    {
        $urlJs = json_encode($quickUrl, JSON_UNESCAPED_SLASHES);
        return <<<HTML
<script>
(function () {
  var quickUrl = {$urlJs};
  var csrfBox = document.getElementById('family-quick-csrf');
  var nameInput = document.getElementById('field-name')
    || document.querySelector('input[name="name"]');
  var fatherSel = document.getElementById('field-father_id');
  var givenName = '';

  function csrfToken() {
    if (!csrfBox) return '';
    var input = csrfBox.querySelector('input[name="_csrf_token"]');
    return input ? input.value : '';
  }

  function fatherName() {
    if (!fatherSel) return '';
    var v = fatherSel.value;
    if (!v || v === '0' || v === '__new__') return '';
    var opt = fatherSel.options[fatherSel.selectedIndex];
    return opt ? String(opt.textContent || '').trim() : '';
  }

  function extractGiven(full, currentFather) {
    full = String(full || '').trim().replace(/\\s+/g, ' ');
    if (!full) return '';
    if (currentFather) {
      var suffix = ' ' + currentFather;
      if (full.length > suffix.length && full.slice(-suffix.length) === suffix) {
        return full.slice(0, -suffix.length).trim();
      }
    }
    // First token is the given name (Somali-style: Mohamed + FatherFullName).
    return full.split(' ')[0];
  }

  function syncFullName(force) {
    if (!nameInput) return;
    var dad = fatherName();
    var current = String(nameInput.value || '').trim().replace(/\\s+/g, ' ');
    if (!givenName) {
      givenName = extractGiven(current, dad);
    }
    if (!givenName) return;
    if (!dad) {
      if (force) nameInput.value = givenName;
      return;
    }
    var next = givenName + ' ' + dad;
    if (force || current === '' || current === givenName || current.indexOf(givenName + ' ') === 0) {
      nameInput.value = next;
    }
  }

  if (nameInput) {
    givenName = extractGiven(nameInput.value, fatherName());
    nameInput.addEventListener('input', function () {
      var dad = fatherName();
      var val = String(nameInput.value || '');
      // While typing, treat text before father suffix (or first word) as given name.
      if (dad && val.indexOf(' ' + dad) !== -1) {
        givenName = val.slice(0, val.indexOf(' ' + dad)).trim() || givenName;
      } else {
        givenName = extractGiven(val, dad) || givenName;
      }
    });
    nameInput.addEventListener('blur', function () {
      syncFullName(true);
    });
  }

  function bind(select) {
    if (!select || select.dataset.familyQuickBound === '1') return;
    select.dataset.familyQuickBound = '1';
    var prev = select.value;
    select.addEventListener('focus', function () { prev = select.value; });
    select.addEventListener('change', function () {
      if (select.value !== '__new__') {
        prev = select.value;
        if (select === fatherSel) syncFullName(true);
        return;
      }
      var gender = select.getAttribute('data-family-quick') || 'male';
      var label = gender === 'female' ? 'mother' : 'father';
      var name = window.prompt('Name of new ' + label + ':');
      if (!name || !String(name).trim()) {
        select.value = prev || '0';
        return;
      }
      select.disabled = true;
      var fd = new FormData();
      fd.append('_csrf_token', csrfToken());
      fd.append('name', String(name).trim());
      fd.append('gender', gender);
      fetch(quickUrl, {
        method: 'POST',
        body: fd,
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok || !data.person) {
            throw new Error((data && data.message) || 'Could not create person.');
          }
          var opt = document.createElement('option');
          opt.value = String(data.person.id);
          opt.textContent = data.person.name;
          var addOpt = select.querySelector('option[value="__new__"]');
          select.insertBefore(opt, addOpt);
          select.value = opt.value;
          prev = opt.value;
          if (select === fatherSel) syncFullName(true);
        })
        .catch(function (err) {
          alert(err.message || 'Could not create person.');
          select.value = prev || '0';
        })
        .finally(function () {
          select.disabled = false;
        });
    });
  }

  document.querySelectorAll('select[data-family-quick]').forEach(bind);
  // Initial pass if father already chosen (edit form / sticky old input).
  syncFullName(false);
})();
</script>
HTML;
    }
}
