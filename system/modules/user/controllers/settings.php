<?php
declare(strict_types=1);

use System\Engine\Controller;

class UserSettings extends Controller
{
    protected function boot(): void
    {
        // Require login for ALL actions.
        if (!$this->auth->check()) {
            $this->session->set(
                'redirect_after_login',
                $this->url->to('user/settings')
            );
            redirect_to('user/login');
        }
    }

    // =========================================================================
    // Settings overview
    // =========================================================================

    public function indexAction(): void
    {
        $user = $this->auth->current();

        if ($user === null) {
            redirect_to('user/login');
            return;
        }

        echo $this->view->inline(function () use ($user) {
            $this->view->block('right_sidebar', 'Account Settings');

            echo '<h1 class="h3 mb-4">Account Settings</h1>';

            echo '<div class="list-group mb-4">';

            echo '<a href="' . $this->view->e($this->url->to('user/settings/password')) . '"'
               . ' class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">'
               . '<span><i class="bi bi-key me-2"></i>Change password</span>'
               . '<i class="bi bi-chevron-right"></i></a>';

            echo '<a href="' . $this->view->e($this->url->to('user/profile')) . '"'
               . ' class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">'
               . '<span><i class="bi bi-person me-2"></i>Edit profile</span>'
               . '<i class="bi bi-chevron-right"></i></a>';

            echo '<a href="' . $this->view->e($this->url->to('user/settings/delete')) . '"'
               . ' class="list-group-item list-group-item-action text-danger d-flex justify-content-between align-items-center">'
               . '<span><i class="bi bi-exclamation-triangle me-2"></i>Delete account</span>'
               . '<i class="bi bi-chevron-right"></i></a>';

            echo '</div>';

            echo '<p class="text-muted small mb-0">Signed in as '
               . $this->view->e($user->displayLabel())
               . ' &lt;' . $this->view->e($user->email) . '&gt;</p>';
        });
    }

    // =========================================================================
    // Change password
    // =========================================================================

    public function changePasswordAction(): void
    {
        $user = $this->auth->current();

        if ($user === null) {
            redirect_to('user/login');
            return;
        }

        if ($this->request->isPost()) {
            // Passwords MUST be read as 'raw' — the 'string' type runs
            // strip_tags() + trim(), which corrupts passwords.
            $currentPassword = (string) $this->request->post('current_password', 'raw', '');
            $newPassword     = (string) $this->request->post('new_password',     'raw', '');
            $confirmPassword = (string) $this->request->post('confirm_password', 'raw', '');

            if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $this->notify->error('All password fields are required.');
            } elseif (!password_verify($currentPassword, $user->passwordHash)) {
                $this->notify->error('Current password is incorrect.');
            } elseif (mb_strlen($newPassword) < 8) {
                $this->notify->error('New password must be at least 8 characters.');
            } elseif ($newPassword !== $confirmPassword) {
                $this->notify->error('New passwords do not match.');
            } elseif ($newPassword === $currentPassword) {
                $this->notify->error('New password must be different from the current one.');
            } else {
                $user->setPassword($newPassword);

                // Rotate the session so old cookies can't be replayed.
                $this->session->regenerate(true);
                $this->session->regenerateCsrf();

                $this->notify->success('Password changed successfully.');
                redirect_to('user/settings');
                return;
            }
        }

        echo $this->view->inline(function () {
            $this->view->block('right_sidebar', 'Change Password');

            echo '<h1 class="h3 mb-4">Change Password</h1>';

            echo $this->form->open([
                'action' => $this->url->to('user/settings/password'),
                'method' => 'POST',
                'autocomplete' => 'off',
            ]);

            echo $this->form->field([
                'name'         => 'current_password',
                'label'        => 'Current password',
                'type'         => 'password',
                'autocomplete' => 'current-password',
            ]);

            echo $this->form->field([
                'name'         => 'new_password',
                'label'        => 'New password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
                'help'         => 'At least 8 characters.',
            ]);

            echo $this->form->field([
                'name'         => 'confirm_password',
                'label'        => 'Confirm new password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
            ]);

            echo '<button type="submit" class="btn btn-primary">Change password</button>';
            echo ' ';
            echo '<a href="' . $this->view->e($this->url->to('user/settings')) . '"'
               . ' class="btn btn-outline-secondary">Cancel</a>';

            echo $this->form->close();
        });
    }

    // =========================================================================
    // Delete account
    // =========================================================================

    public function deleteAccountAction(): void
    {
        $user = $this->auth->current();

        if ($user === null) {
            redirect_to('user/login');
            return;
        }

        if ($this->request->isPost()) {
            $confirm  = (string) $this->request->post('confirm', 'raw', '');
            $password = (string) $this->request->post('password', 'raw', '');

            if ($confirm !== 'DELETE') {
                $this->notify->error('Please type DELETE in the confirmation field.');
            } elseif (!password_verify($password, $user->passwordHash)) {
                $this->notify->error('Password is incorrect.');
            } else {
                $user->delete();

                $this->session->destroy();
                $this->notify->success('Your account has been deleted.');

                redirect_to('');
                return;
            }
        }

        echo $this->view->inline(function () use ($user) {
            $this->view->block('right_sidebar', 'Delete Account');

            echo '<h1 class="h3 mb-4 text-danger">Delete Account</h1>';

            echo $this->form->alert([
                'type'    => 'danger',
                'message' => 'This action is permanent. All your data — profile, group memberships, '
                           . 'and remember-me tokens — will be removed. This cannot be undone.',
            ]);

            echo $this->form->open([
                'action' => $this->url->to('user/settings/delete'),
                'method' => 'POST',
            ]);

            echo $this->form->field([
                'name'  => 'confirm',
                'label' => 'Type DELETE to confirm',
                'type'  => 'text',
                'help'  => 'Case-sensitive.',
            ]);

            echo $this->form->field([
                'name'         => 'password',
                'label'        => 'Your password',
                'type'         => 'password',
                'autocomplete' => 'current-password',
            ]);

            echo '<button type="submit" class="btn btn-danger">Permanently delete account</button>';
            echo ' ';
            echo '<a href="' . $this->view->e($this->url->to('user/settings')) . '"'
               . ' class="btn btn-outline-secondary">Cancel</a>';

            echo $this->form->close();
        });
    }
}