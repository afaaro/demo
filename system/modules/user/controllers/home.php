<?php
declare(strict_types=1);

use System\Engine\Controller;

class UserHome extends Controller
{
    public function loginAction(): void
    {
        // Already signed in.
        if ($this->auth->check()) {
            redirect_to('user');
            return;
        }

        $login    = '';
        $remember = false;
        $error    = null;

        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
            } catch (\RuntimeException) {
                $error = 'Your session expired. Please reload and try again.';
            }

            if ($error === null) {
                try {
                    $this->request->enforceRateLimit('user.login', 10, 900);
                } catch (\RuntimeException) {
                    $error = 'Too many sign-in attempts. Please wait a few minutes and try again.';
                }
            }

            $login    = trim((string) $this->request->post('username', 'raw', ''));
            $password = (string) $this->request->post('password', 'raw', '');
            $remember = (bool) $this->request->post('remember', 'bool', false);

            // Honeypot — bots often fill hidden fields.
            $honeypot = trim((string) $this->request->post('website', 'raw', ''));
            if ($honeypot !== '') {
                $error = 'Invalid username/email or password.';
            }

            if ($error === null) {
                if ($login === '' || $password === '') {
                    $error = 'Username/email and password are required.';
                } else {
                    $user = $this->auth->findByLogin($login);

                    if ($user !== null && password_verify($password, $user->passwordHash)) {
                        if (!$user->isActive()) {
                            $error = match ($user->status) {
                                'pending'   => 'Your account is awaiting admin approval.',
                                'suspended' => 'Your account has been suspended.',
                                default     => 'Your account cannot sign in right now.',
                            };
                        } else {
                            if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                if ($hash !== false) {
                                    $user->setPasswordHash($hash);
                                }
                            }

                            $user->touchLastLogin();
                            $this->auth->login($user);
                            $next = (string) ($this->request->get('next', 'raw', '')
                                ?: $this->session->pull('redirect_after_login')
                                ?: '');

                            if ($next === '' || str_contains($next, '://')) {
                                redirect_to('user');
                            }
                            redirect($next);
                            return;
                        }
                    } else {
                        $error = 'Invalid username/email or password.';
                    }
                }
            }
        }

        echo $this->view->inline(function () use ($login, $remember, $error) {
            $this->view->assign('title', 'Sign in');
            $this->view->block('right_sidebar', 'Login');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<h1 class="h3 mb-4">Sign in</h1>';

            if ($error !== null) {
                echo $this->form->alert(['type' => 'danger', 'message' => $error]);
            }

            echo $this->form->open([
                'action'       => $this->url->to('user/login'),
                'method'       => 'POST',
                'autocomplete' => 'on',
            ]);

            // Honeypot
            echo '<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">';
            echo '<label for="login_website">Website</label>';
            echo '<input type="text" name="website" id="login_website" value="" tabindex="-1" autocomplete="off">';
            echo '</div>';

            echo $this->form->field([
                'name'         => 'username',
                'label'        => 'Username or Email',
                'type'         => 'text',
                'value'        => $login,
                'autocomplete' => 'username',
            ]);

            echo $this->form->field([
                'name'         => 'password',
                'label'        => 'Password',
                'type'         => 'password',
                'autocomplete' => 'current-password',
            ]);

            echo $this->form->field([
                'name'    => 'remember',
                'label'   => 'Remember me',
                'type'    => 'checkbox',
                'value'   => 1,
                'checked' => $remember,
            ]);

            echo '<div class="d-flex flex-wrap align-items-center gap-3 mb-3">';
            echo '<button type="submit" class="btn btn-primary">Sign In</button>';
            echo '<a href="' . $e($this->url->to('user/password/forgot')) . '">Forgot password?</a>';
            echo '</div>';

            echo $this->form->close();

            echo '<p class="text-muted small mb-0">Need an account? '
               . '<a href="' . $e($this->url->to('user/register')) . '">Register</a></p>';
        });
    }

    public function logoutAction(): void
    {
        $this->auth->logout();
        $this->notify->success('You have been signed out.');
        redirect($this->url->to('user'));
    }

    public function indexAction(): void
    {
        $user = $this->auth->current();

        echo $this->view->inline(function () use ($user) {
            $this->view->block('right_sidebar', 'User');

            if ($user !== null) {
                echo '<h1 class="h3 mb-4">Welcome, '
                . $this->view->e($user->displayLabel())
                . '</h1>';

                echo '<p class="text-muted">You are signed in.</p>';

                echo '<div class="list-group mb-4">';

                echo '<a href="' . $this->view->e($this->url->to('user/profile')) . '"'
                . ' class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">'
                . '<span><i class="bi bi-person me-2"></i>My Profile</span>'
                . '<i class="bi bi-chevron-right"></i></a>';

                echo '<a href="' . $this->view->e($this->url->to('user/settings')) . '"'
                . ' class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">'
                . '<span><i class="bi bi-gear me-2"></i>Account Settings</span>'
                . '<i class="bi bi-chevron-right"></i></a>';

                echo '<a href="' . $this->view->e($this->url->to('user/groups')) . '"'
                . ' class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">'
                . '<span><i class="bi bi-people me-2"></i>My Groups</span>'
                . '<i class="bi bi-chevron-right"></i></a>';

                echo '</div>';

                echo '<a href="' . $this->view->e($this->url->to('user/logout')) . '"'
                . ' class="btn btn-outline-danger">Sign out</a>';

                return;
            }

            redirect_to('user/login');
        });
    }
}
