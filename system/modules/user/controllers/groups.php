<?php
declare(strict_types=1);

use System\Engine\Controller;

class UserGroups extends Controller
{
    protected function boot(): void
    {
        if (!$this->auth->check()) {
            $this->session->set(
                'redirect_after_login',
                $this->url->to('user/groups')
            );
            redirect_to('user/login');
        }
    }

    // =========================================================================
    // My groups
    // =========================================================================

    public function indexAction(): void
    {
        $user = $this->auth->current();

        if ($user === null) {
            redirect_to('user/login');
            return;
        }

        echo $this->view->inline(function () use ($user) {
            $this->view->block('right_sidebar', 'My Groups');

            echo '<h1 class="h3 mb-4">My Groups</h1>';

            if (empty($user->groups)) {
                echo '<div class="alert alert-info">'
                   . 'You are not a member of any groups yet.'
                   . '</div>';
            } else {
                echo '<div class="list-group mb-4">';

                foreach ($user->groups as $group) {
                    echo '<div class="list-group-item">';
                    echo '<div class="d-flex justify-content-between align-items-start">';

                    echo '<div>';
                    echo '<h6 class="mb-1">'
                       . $this->view->e($group->label)
                       . ' <span class="text-muted small">('
                       . $this->view->e($group->name)
                       . ')</span>'
                       . '</h6>';

                    if ($group->description !== '') {
                        echo '<p class="mb-1 text-muted small">'
                           . $this->view->e($group->description)
                           . '</p>';
                    }

                    if (!empty($group->permissions)) {
                        echo '<p class="mb-0">';
                        foreach ($group->permissions as $perm) {
                            echo '<span class="badge text-bg-secondary me-1">'
                               . $this->view->e($perm)
                               . '</span>';
                        }
                        echo '</p>';
                    }
                    echo '</div>';

                    if ($group->isSystem) {
                        echo '<span class="badge text-bg-primary">System</span>';
                    }

                    echo '</div>';   // d-flex
                    echo '</div>';   // list-group-item
                }

                echo '</div>';
            }

            if ($user->isAdmin()) {
                echo '<a href="' . $this->view->e($this->url->to('user/groups/all')) . '"'
                   . ' class="btn btn-outline-primary">View all groups</a>';
            }
        });
    }

    // =========================================================================
    // All groups (admin only)
    // =========================================================================

    public function allAction(): void
    {
        $user = $this->auth->current();

        if ($user === null || !$user->isAdmin()) {
            $this->notify->error('Only administrators can view all groups.');
            redirect_to('user/groups');
            return;
        }

        $allGroups = \System\Services\UserGroup::all();

        echo $this->view->inline(function () use ($allGroups) {
            $this->view->block('right_sidebar', 'All Groups');

            echo '<h1 class="h3 mb-4">All Groups</h1>';

            if (empty($allGroups)) {
                echo '<div class="alert alert-info">No groups defined.</div>';
            } else {
                echo '<div class="table-responsive">';
                echo '<table class="table table-striped align-middle">';
                echo '<thead><tr>'
                   . '<th>Name</th>'
                   . '<th>Label</th>'
                   . '<th>Description</th>'
                   . '<th>Permissions</th>'
                   . '<th></th>'
                   . '</tr></thead><tbody>';

                foreach ($allGroups as $group) {
                    echo '<tr>';

                    echo '<td><code>' . $this->view->e($group->name) . '</code>'
                       . ($group->isSystem
                            ? ' <span class="badge text-bg-primary">System</span>'
                            : '')
                       . '</td>';

                    echo '<td>' . $this->view->e($group->label) . '</td>';
                    echo '<td class="text-muted small">' . $this->view->e($group->description) . '</td>';

                    echo '<td>';
                    if (empty($group->permissions)) {
                        echo '<span class="text-muted small">none</span>';
                    } else {
                        foreach ($group->permissions as $perm) {
                            echo '<span class="badge text-bg-secondary me-1">'
                               . $this->view->e($perm)
                               . '</span>';
                        }
                    }
                    echo '</td>';

                    echo '<td class="text-end">'
                       . '<a href="' . $this->view->e($this->url->to('user/groups/view', ['id' => $group->id])). '"'
                       . ' class="btn btn-sm btn-outline-secondary">View</a>'
                       . '</td>';

                    echo '</tr>';
                }

                echo '</tbody></table>';
                echo '</div>';
            }

            echo '<a href="' . $this->view->e($this->url->to('user/groups')) . '"'
               . ' class="btn btn-outline-secondary">Back to my groups</a>';
        });
    }

    public function viewAction(): void
    {
        $id = $this->request->param('id', 'int');
        if ($id === null || $id <= 0) {
            throw new \System\Engine\NotFoundException('Group not found.');
        }

        $group = \System\Services\UserGroup::find($id);
        if ($group === null) {
            throw new \System\Engine\NotFoundException('Group not found.');
        }

        echo $this->view->inline(function () use ($group) {
            $this->view->block('right_sidebar', 'Group');

            echo '<h1 class="h3 mb-4">' . $this->view->e($group->label) . '</h1>';

            echo '<dl class="row">';
            echo '<dt class="col-sm-3">Name</dt>';
            echo '<dd class="col-sm-9"><code>' . $this->view->e($group->name) . '</code></dd>';

            echo '<dt class="col-sm-3">Description</dt>';
            echo '<dd class="col-sm-9">' . $this->view->e($group->description) . '</dd>';

            echo '<dt class="col-sm-3">System</dt>';
            echo '<dd class="col-sm-9">' . ($group->isSystem ? 'Yes' : 'No') . '</dd>';

            echo '<dt class="col-sm-3">Permissions</dt>';
            echo '<dd class="col-sm-9">';
            if (empty($group->permissions)) {
                echo '<span class="text-muted">None</span>';
            } else {
                foreach ($group->permissions as $perm) {
                    echo '<span class="badge text-bg-secondary me-1">'
                    . $this->view->e($perm) . '</span>';
                }
            }
            echo '</dd>';

            echo '</dl>';

            echo '<a href="' . $this->view->e($this->url->to('user/groups/all')) . '"'
            . ' class="btn btn-outline-secondary">Back</a>';
        });
    }
}