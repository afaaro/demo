<?php
declare(strict_types=1);

use System\Engine\Controller;

class UserProfile extends Controller
{
    protected function boot(): void
    {
        // Require login for ALL actions in this controller.
        if (!$this->auth->check()) {
            $this->session->set(
                'redirect_after_login',
                $this->url->to('user/profile')
            );
            redirect_to('user/login');
        }
    }

    // =========================================================================
    // Profile overview
    // =========================================================================

    public function indexAction(): void
    {
        $user = $this->auth->current();

        if ($user === null) {
            $this->session->remove('user_id');
            $this->notify->error('Account not found. Please sign in again.');
            redirect_to('user/login');
            return;
        }

        echo $this->view->inline(function () use ($user) {
            $this->view->block('right_sidebar', 'Profile');

            echo '<h1 class="h3 mb-4">My Profile</h1>';

            echo '<dl class="row">';

            echo '<dt class="col-sm-3">Display name</dt>';
            echo '<dd class="col-sm-9">' . $this->view->e($user->displayLabel()) . '</dd>';

            echo '<dt class="col-sm-3">Username</dt>';
            echo '<dd class="col-sm-9">' . $this->view->e($user->username) . '</dd>';

            echo '<dt class="col-sm-3">Email</dt>';
            echo '<dd class="col-sm-9">' . $this->view->e($user->email) . '</dd>';

            echo '<dt class="col-sm-3">Joined</dt>';
            echo '<dd class="col-sm-9">'
                . ($user->createdAt !== null
                    ? $this->view->e($user->createdAt)
                    : '&mdash;')
                . '</dd>';

            echo '<dt class="col-sm-3">Email verified</dt>';
            echo '<dd class="col-sm-9">'
                . ($user->emailVerified
                    ? '<span class="badge text-bg-success">Verified</span>'
                    : '<span class="badge text-bg-warning">Unverified</span>')
                . '</dd>';

            echo '</dl>';

            echo '<a href="' . $this->view->e($this->url->to('user/profile/edit')) . '"'
               . ' class="btn btn-primary">Edit Profile</a>';
            echo ' ';
            echo '<a href="' . $this->view->e($this->url->to('user/settings')) . '"'
               . ' class="btn btn-outline-secondary">Settings</a>';
        });
    }

    // =========================================================================
    // Edit profile
    // =========================================================================

    public function editAction(): void
    {
        $user = $this->auth->current();

        if ($user === null) {
            redirect_to('user/login');
            return;
        }

        // Handle POST
        if ($this->request->isPost()) {
            $displayName = trim((string) $this->request->post('display_name', 'string', ''));
            $len         = mb_strlen($displayName);

            if ($len < 2 || $len > 128) {
                $this->notify->error('Display name must be between 2 and 128 characters.');
            } else {
                $user->setDisplayName($displayName);
                $this->notify->success('Profile updated successfully.');
                redirect_to('user/profile');
                return;
            }
        }

        // Render the edit form
        $value = $this->request->isPost()
            ? (string) $this->request->post('display_name', 'raw', '')
            : $user->displayLabel();

        echo $this->view->inline(function () use ($user, $value) {
            $this->view->block('right_sidebar', 'Edit Profile');

            echo '<h1 class="h3 mb-4">Edit Profile</h1>';

            echo $this->form->open([
                'action' => $this->url->to('user/profile/edit'),
                'method' => 'POST',
            ]);

            echo $this->form->field([
                'name'  => 'display_name',
                'label' => 'Display name',
                'type'  => 'text',
                'value' => $value,
                'help'  => 'Between 2 and 128 characters. Shown in the header and on your public profile.',
            ]);

            echo '<button type="submit" class="btn btn-primary">Save changes</button>';
            echo ' ';
            echo '<a href="' . $this->view->e($this->url->to('user/profile')) . '"'
               . ' class="btn btn-outline-secondary">Cancel</a>';

            echo $this->form->close();
        });
    }
}