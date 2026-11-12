<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/helpers/functions.php';

use Module\Family\Services\MarriageRepository;
use Module\Family\Services\PersonRepository;
use System\Engine\Controller;

class FamilyAdminHome extends Controller
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
        $people = $this->people->counts();
        $marriages = $this->marriages->countsByStatus();
        $latest = $this->people->search([], 8, 0, 'created_at', 'desc');

        echo $this->view->inline(function () use ($people, $marriages, $latest) {
            $this->view->assign('title', 'Family');
            $this->view->block('right_sidebar', 'Family');
            $e = fn(string|int|float $s): string => $this->view->e((string) $s);
            $max = family_max_wives();

            echo '<div class="sf-page">';
            echo '<div class="sf-head">';
            echo '<div><h1>Family</h1><p>People, marriages (max ' . (int) $max . ' active wives), and tree.</p></div>';
            echo '<div class="sf-actions">';
            echo '<a href="' . $e($this->url->to('family/admin/tools/relate')) . '" class="sf-btn"><i class="bi bi-diagram-2"></i>Relationship</a>';
            echo '<a href="' . $e($this->url->to('family/admin/marriages/add')) . '" class="sf-btn"><i class="bi bi-heart"></i>New marriage</a>';
            echo '<a href="' . $e($this->url->to('family/admin/people/add')) . '" class="sf-btn is-primary"><i class="bi bi-plus-lg"></i>New person</a>';
            echo '</div></div>';

            echo '<div class="sf-stats">';
            foreach ([
                ['People', $people['total'], $this->url->to('family/admin/people')],
                ['Men', $people['male'], $this->url->to('family/admin/people', ['gender' => 'male'])],
                ['Women', $people['female'], $this->url->to('family/admin/people', ['gender' => 'female'])],
                ['Active marriages', $marriages['active'], $this->url->to('family/admin/marriages', ['status' => 'active'])],
            ] as [$label, $value, $url]) {
                echo '<a href="' . $e($url) . '" class="sf-stat"><span>' . $e($label) . '</span><strong>' . (int) $value . '</strong></a>';
            }
            echo '</div>';

            echo '<section class="sf-card">';
            echo '<div class="sf-card-h"><h2>Recent people</h2><a href="' . $e($this->url->to('family/admin/people')) . '">View all</a></div>';
            if ($latest === []) {
                echo '<p class="sf-empty">No people yet. <a href="' . $e($this->url->to('family/admin/people/add')) . '">Add the first person</a>.</p>';
            } else {
                echo '<div class="table-responsive"><table class="sf-table"><thead><tr><th>Name</th><th>Gender</th><th></th></tr></thead><tbody>';
                foreach ($latest as $p) {
                    echo '<tr><td><a class="sf-title" href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->id])) . '">' . $e($p->name) . '</a></td>';
                    echo '<td>' . $e(ucfirst($p->gender)) . '</td>';
                    echo '<td class="c-acts"><a class="sf-btn" href="' . $e($this->url->to('family/admin/people/edit', ['id' => $p->id])) . '">Edit</a></td></tr>';
                }
                echo '</tbody></table></div>';
            }
            echo '</section></div>';
        });
    }
}
