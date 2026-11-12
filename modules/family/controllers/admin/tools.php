<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Family\Services\MergeService;
use Module\Family\Services\PersonRepository;
use Module\Family\Services\RelationshipService;
use System\Engine\Controller;

class FamilyAdminTools extends Controller
{
    private PersonRepository $people;
    private RelationshipService $relationships;
    private MergeService $merge;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->people = new PersonRepository($this->db);
        $this->relationships = new RelationshipService($this->db);
        $this->merge = new MergeService($this->db);
        $this->view->addModuleStyle('family', 'family.css');
    }

    public function relateAction(): void
    {
        $fromId = (int) $this->request->get('from_id', 'int', (int) $this->request->post('from_id', 'int', 0));
        $toId = (int) $this->request->get('to_id', 'int', (int) $this->request->post('to_id', 'int', 0));
        $result = null;
        if ($fromId > 0 && $toId > 0) {
            $result = $this->relationships->find($fromId, $toId);
        }

        $opts = $this->personSelectOptions();

        echo $this->view->inline(function () use ($fromId, $toId, $result, $opts) {
            $this->view->assign('title', 'Relationship finder');
            $this->view->block('right_sidebar', 'Family');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page"><div class="sf-head">';
            echo '<div><h1>Relationship finder</h1><p>See how two people are connected through parents, marriages, and children.</p></div>';
            echo '<div class="sf-actions"><a class="sf-btn" href="' . $e($this->url->to('family/admin')) . '">Dashboard</a></div>';
            echo '</div>';

            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->form->open(['action' => $this->url->to('family/admin/tools/relate'), 'method' => 'GET']);
            echo $this->form->row(['fields' => [
                [
                    'type' => 'select', 'name' => 'from_id', 'label' => 'Person A',
                    'options' => $opts, 'value' => (string) $fromId, 'required' => true, 'col' => 'col-md-6',
                ],
                [
                    'type' => 'select', 'name' => 'to_id', 'label' => 'Person B',
                    'options' => $opts, 'value' => (string) $toId, 'required' => true, 'col' => 'col-md-6',
                ],
            ]]);
            echo '<div class="sf-actions"><button type="submit" class="sf-btn is-primary">Find relationship</button></div>';
            echo $this->form->close();
            echo '</div></section>';

            if ($result !== null) {
                echo '<section class="sf-card mt-3"><div class="sf-card-h"><h2>Result</h2></div>';
                if (!$result['found']) {
                    echo '<p class="sf-empty">' . $e($result['label'] !== '' ? $result['label'] : 'No relationship found.') . '</p>';
                } else {
                    echo '<p><strong>' . $e($result['label']) . '</strong></p>';
                    echo '<ol class="fam-relate-path">';
                    foreach ($result['steps'] as $i => $step) {
                        $p = $step['person'];
                        $via = (string) $step['via'];
                        echo '<li>';
                        if ($i > 0) {
                            echo '<span class="sf-sub">' . $e($this->viaLabel($via)) . '</span> ';
                        }
                        echo '<a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->id])) . '">'
                            . $e($p->name) . '</a></li>';
                    }
                    echo '</ol>';
                }
                echo '</section>';
            }
            echo '</div>';
        });
    }

    public function mergeAction(): void
    {
        $groups = $this->merge->duplicateGroups();
        $keepId = (int) $this->request->post('keep_id', 'int', 0);
        $removeId = (int) $this->request->post('remove_id', 'int', 0);

        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
                $merged = $this->merge->merge($keepId, $removeId);
                $this->notify->success('Merged into “' . $merged->name . '”.');
                redirect_to('family/admin/people/edit', ['id' => (int) $merged->id]);
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
            }
        }

        $opts = $this->personSelectOptions();

        echo $this->view->inline(function () use ($groups, $opts, $keepId, $removeId) {
            $this->view->assign('title', 'Merge duplicates');
            $this->view->block('right_sidebar', 'Family');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            echo '<div class="sf-page"><div class="sf-head">';
            echo '<div><h1>Merge duplicates</h1><p>Keep one person and move parents, children, and marriages from the other.</p></div>';
            echo '<div class="sf-actions"><a class="sf-btn" href="' . $e($this->url->to('family/admin/tools/relate')) . '">Relationship finder</a></div>';
            echo '</div>';

            echo '<section class="sf-card"><div class="sf-card-h"><h2>Merge two people</h2></div><div class="sf-form">';
            echo $this->form->open(['action' => $this->url->to('family/admin/tools/merge'), 'method' => 'POST']);
            echo $this->form->row(['fields' => [
                [
                    'type' => 'select', 'name' => 'keep_id', 'label' => 'Keep',
                    'options' => $opts, 'value' => (string) $keepId, 'required' => true, 'col' => 'col-md-6',
                    'help' => 'This record stays.',
                ],
                [
                    'type' => 'select', 'name' => 'remove_id', 'label' => 'Merge away',
                    'options' => $opts, 'value' => (string) $removeId, 'required' => true, 'col' => 'col-md-6',
                    'help' => 'This record is deleted after links move.',
                ],
            ]]);
            echo '<div class="sf-actions"><button type="submit" class="sf-btn is-danger" onclick="return confirm(\'Merge and delete the second person?\');">Merge</button></div>';
            echo $this->form->close();
            echo '</div></section>';

            echo '<section class="sf-card mt-3"><div class="sf-card-h"><h2>Possible duplicates</h2></div>';
            if ($groups === []) {
                echo '<p class="sf-empty">No exact name duplicates found.</p>';
            } else {
                foreach ($groups as $g) {
                    echo '<div class="mb-3"><strong>' . $e($g['name']) . '</strong> <span class="sf-sub">(' . (int) $g['count'] . ')</span><ul>';
                    foreach ($g['people'] as $p) {
                        echo '<li><a href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->id])) . '">'
                            . $e($p->name) . '</a> <span class="sf-sub">#' . (int) $p->id
                            . ($p->birthDate ? ' · b. ' . $e($p->birthDate) : '')
                            . ' · ' . $e($p->slug) . '</span></li>';
                    }
                    echo '</ul></div>';
                }
            }
            echo '</section></div>';
        });
    }

    /** @return array<string,string> */
    private function personSelectOptions(): array
    {
        $opts = ['0' => '— Choose —'];
        foreach ($this->people->options(null, false) as $p) {
            $opts[(string) $p->id] = $p->name . ' (#' . $p->id . ')';
        }
        return $opts;
    }

    private function viaLabel(string $via): string
    {
        return match ($via) {
            'father' => '↑ father',
            'mother' => '↑ mother',
            'child'  => '↓ child',
            'spouse' => '↔ spouse',
            'self', 'start' => '',
            default  => $via,
        };
    }
}
