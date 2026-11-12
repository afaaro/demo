<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Family\Services\Marriage;
use Module\Family\Services\MarriageRepository;
use Module\Family\Services\PersonRepository;
use System\Engine\Controller;
use System\Library\Dataset;

class FamilyAdminMarriages extends Controller
{
    private PersonRepository $people;
    private MarriageRepository $marriages;

    protected function boot(): void
    {
        if (!$this->auth->check()) {
            redirect_to('user/login');
        }
        $this->people = new PersonRepository($this->db);
        $this->marriages = new MarriageRepository($this->db, $this->people);
    }

    public function indexAction(): void
    {
        $max = family_max_wives();
        $dataset = (new Dataset($this->registry))
            ->perPage(20)
            ->searchPlaceholder('Search husband or wife…')
            ->filter('status', 'select', [
                ''         => 'All statuses',
                'active'   => 'Active',
                'divorced' => 'Divorced',
                'widowed'  => 'Widowed',
            ])
            ->columns([
                'couple'  => 'Couple',
                'status'  => 'Status',
                'dates'   => 'Dates',
                'slot'    => 'Wives',
                'actions' => '',
            ])
            ->query(function ($db, array $state): array {
                $filters = [
                    'q'      => $state['q'],
                    'status' => $state['filters']['status'] ?? '',
                ];
                $items = $this->marriages->search($filters, $state['per_page'], $state['offset']);
                return [
                    'rows'  => array_map(static fn(Marriage $m): array => ['_m' => $m], $items),
                    'total' => $this->marriages->countSearch($filters),
                ];
            });

        $e = fn(string|int|float $s): string => $this->view->e((string) $s);

        $dataset->columns([
            'couple' => function (array $row) use ($e): string {
                $m = $row['_m'];
                $hUrl = $this->url->to('family/admin/people/edit', ['id' => (int) $m->husbandId]);
                $wUrl = $this->url->to('family/admin/people/edit', ['id' => (int) $m->wifeId]);
                return '<span class="sf-title"><a href="' . $e($hUrl) . '">' . $e((string) $m->husbandName) . '</a></span>'
                    . '<span class="sf-sub">+ <a href="' . $e($wUrl) . '">' . $e((string) $m->wifeName) . '</a></span>';
            },
            'status' => function (array $row): string {
                return match ($row['_m']->status) {
                    'active'   => '<span class="sf-pill is-ok">Active</span>',
                    'divorced' => '<span class="sf-pill is-off">Divorced</span>',
                    'widowed'  => '<span class="sf-pill is-wait">Widowed</span>',
                    default    => htmlspecialchars($row['_m']->status, ENT_QUOTES, 'UTF-8'),
                };
            },
            'dates' => function (array $row) use ($e): string {
                $m = $row['_m'];
                $text = (string) ($m->marriedOn ?? '—');
                if ($m->endedOn) {
                    $text .= ' → ' . $m->endedOn;
                }
                return $e($text);
            },
            'slot' => function (array $row) use ($e, $max): string {
                $m = $row['_m'];
                $n = $this->marriages->countActiveWives($m->husbandId);
                return $e($n . '/' . $max);
            },
            'actions' => function (array $row) use ($e): string {
                $m = $row['_m'];
                $html = '<div class="sf-row-acts">';
                if ($m->isActive()) {
                    $html .= '<form method="post" action="' . $e($this->url->to('family/admin/marriages/divorce'))
                        . '" onsubmit="return confirm(\'Mark this marriage as divorced? This frees a wife slot.\');">';
                    $html .= $this->request->csrfField();
                    $html .= '<input type="hidden" name="id" value="' . (int) $m->id . '">';
                    $html .= '<button type="submit" class="sf-btn">Divorce</button></form>';
                    $html .= '<form method="post" action="' . $e($this->url->to('family/admin/marriages/widow'))
                        . '" onsubmit="return confirm(\'Mark widowed? This frees a wife slot.\');">';
                    $html .= $this->request->csrfField();
                    $html .= '<input type="hidden" name="id" value="' . (int) $m->id . '">';
                    $html .= '<button type="submit" class="sf-btn">Widowed</button></form>';
                }
                $html .= '<form method="post" action="' . $e($this->url->to('family/admin/marriages/delete'))
                    . '" onsubmit="return confirm(\'Delete this marriage record?\');">';
                $html .= $this->request->csrfField();
                $html .= '<input type="hidden" name="id" value="' . (int) $m->id . '">';
                $html .= '<button type="submit" class="sf-btn is-danger">Delete</button></form></div>';
                return $html;
            },
        ]);

        echo $this->view->inline(function () use ($dataset, $e, $max) {
            $this->view->assign('title', 'Family marriages');
            $this->view->block('right_sidebar', 'Family');
            echo '<div class="sf-page"><div class="sf-head">';
            echo '<div><h1>Marriages</h1><p>A husband may have up to ' . (int) $max . ' active wives. Divorce frees a slot.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('family/admin/marriages/add')) . '" class="sf-btn is-primary"><i class="bi bi-plus-lg"></i>New marriage</a>';
            echo '</div></div><section class="sf-card">' . $dataset->render() . '</section></div>';
        });
    }

    public function addAction(): void
    {
        $old = [
            'husband_id' => (int) $this->request->get('husband_id', 'int', 0),
            'wife_id'    => (int) $this->request->get('wife_id', 'int', 0),
            'married_on' => date('Y-m-d'),
        ];

        if ($this->request->isPost()) {
            $this->guardCsrf();
            $old = [
                'husband_id' => (int) $this->request->post('husband_id', 'int', 0),
                'wife_id'    => (int) $this->request->post('wife_id', 'int', 0),
                'married_on' => trim((string) $this->request->post('married_on', 'string', '')),
            ];
            try {
                $m = $this->marriages->create($old);
                $active = $this->marriages->countActiveWives($m->husbandId);
                $max = family_max_wives();
                $this->notify->success(
                    "Marriage created. Husband now has {$active}/{$max} active wives."
                );
                redirect_to('family/admin/marriages');
                return;
            } catch (\Throwable $ex) {
                $this->notify->error($ex->getMessage());
            }
        }

        $husbands = $this->people->options('male');
        $wives = $this->people->options('female');
        $max = family_max_wives();

        echo $this->view->inline(function () use ($old, $husbands, $wives, $max) {
            $this->view->assign('title', 'New marriage');
            $this->view->block('right_sidebar', 'Family');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);

            $husbandOpts = ['0' => '— Select husband —'];
            foreach ($husbands as $p) {
                $n = $this->marriages->countActiveWives((int) $p->id);
                $husbandOpts[(string) $p->id] = $p->name . " ({$n}/{$max})";
            }
            $wifeOpts = ['0' => '— Select wife —'];
            foreach ($wives as $p) {
                $busy = $this->marriages->wifeHasActiveMarriage((int) $p->id) ? ' · married' : '';
                $wifeOpts[(string) $p->id] = $p->name . $busy;
            }

            echo '<div class="sf-page"><div class="sf-head">';
            echo '<div><h1>New marriage</h1><p>Max ' . (int) $max . ' active wives per husband.</p></div>';
            echo '<div class="sf-actions"><a class="sf-btn" href="' . $e($this->url->to('family/admin/marriages')) . '"><i class="bi bi-arrow-left"></i>Back</a></div></div>';
            echo '<section class="sf-card"><div class="sf-form">';
            echo $this->form->open(['action' => $this->url->to('family/admin/marriages/add'), 'method' => 'POST']);
            echo $this->form->field([
                'type' => 'select', 'name' => 'husband_id', 'label' => 'Husband',
                'options' => $husbandOpts, 'value' => (string) ((int) $old['husband_id']), 'required' => true,
            ]);
            echo $this->form->field([
                'type' => 'select', 'name' => 'wife_id', 'label' => 'Wife',
                'options' => $wifeOpts, 'value' => (string) ((int) $old['wife_id']), 'required' => true,
            ]);
            echo $this->form->field([
                'type' => 'date', 'name' => 'married_on', 'label' => 'Married on',
                'value' => (string) $old['married_on'],
            ]);
            echo '<div class="sf-actions mt-4">';
            echo '<button type="submit" class="sf-btn is-primary">Create marriage</button>';
            echo '<a class="sf-btn" href="' . $e($this->url->to('family/admin/marriages')) . '">Cancel</a>';
            echo '</div>' . $this->form->close();
            echo '</div></section></div>';
        });
    }

    public function divorceAction(): void
    {
        if (!$this->request->isPost()) {
            $this->redirectAfterMarriageAction();
            return;
        }
        $this->guardCsrf();
        $id = (int) $this->request->post('id', 'int', 0);
        try {
            $m = $this->marriages->divorce($id);
            $left = $this->marriages->countActiveWives($m->husbandId);
            $max = family_max_wives();
            $this->notify->success("Marriage divorced. Husband now has {$left}/{$max} active wives.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        $this->redirectAfterMarriageAction();
    }

    public function widowAction(): void
    {
        if (!$this->request->isPost()) {
            $this->redirectAfterMarriageAction();
            return;
        }
        $this->guardCsrf();
        $id = (int) $this->request->post('id', 'int', 0);
        try {
            $m = $this->marriages->markWidowed($id);
            $left = $this->marriages->countActiveWives($m->husbandId);
            $max = family_max_wives();
            $this->notify->success("Marked widowed. Husband now has {$left}/{$max} active wives.");
        } catch (\Throwable $ex) {
            $this->notify->error($ex->getMessage());
        }
        $this->redirectAfterMarriageAction();
    }

    public function deleteAction(): void
    {
        if (!$this->request->isPost()) {
            $this->redirectAfterMarriageAction();
            return;
        }
        $this->guardCsrf();
        $id = (int) $this->request->post('id', 'int', 0);
        $m = $this->marriages->find($id);
        if ($m !== null) {
            $this->marriages->delete($id);
            $this->notify->success('Marriage deleted.');
        } else {
            $this->notify->error('Marriage not found.');
        }
        $this->redirectAfterMarriageAction();
    }

    private function redirectAfterMarriageAction(): never
    {
        $returnPerson = (int) $this->request->post('return_person', 'int', 0);
        if ($returnPerson > 0) {
            redirect_to('family/admin/people/edit', ['id' => $returnPerson]);
        }
        redirect_to('family/admin/marriages');
    }

    private function guardCsrf(): void
    {
        try {
            $this->request->checkToken();
        } catch (\RuntimeException) {
            $this->notify->error('Session expired. Please try again.');
            $this->redirectAfterMarriageAction();
        }
    }
}
