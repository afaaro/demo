<?php
declare(strict_types=1);

use System\Engine\Controller;
use System\Services\PasswordResetService;

class UserPassword extends Controller
{
    private PasswordResetService $resets;

    protected function boot(): void
    {
        $this->resets = new PasswordResetService(
            $this->db,
            $this->mail,
            $this->url,
        );
    }

    public function forgotAction(): void
    {
        if ($this->auth->check()) {
            redirect_to('user');
            return;
        }

        $email = '';
        $sent = false;
        $error = null;

        if ($this->request->isPost()) {
            try {
                $this->request->checkToken();
                $this->request->enforceRateLimit('user.password.forgot', 5, 3600);
            } catch (\RuntimeException $e) {
                $error = $e->getCode() === 429
                    ? 'Too many reset requests. Please try again later.'
                    : 'Your session expired. Please reload and try again.';
            }

            $email = strtolower(trim((string) $this->request->post('email', 'string', '')));

            if ($error === null) {
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Enter a valid email address.';
                } else {
                    $this->resets->requestReset($email, $this->request->ip());
                    $sent = true;
                }
            }
        }

        echo $this->view->inline(function () use ($email, $sent, $error) {
            $this->view->assign('title', 'Forgot password');
            $this->view->block('right_sidebar', 'Login');

            echo '<h1 class="h3 mb-4">Forgot password</h1>';

            if ($sent) {
                echo $this->form->alert([
                    'type'    => 'success',
                    'message' => 'If that email matches an active account, we sent a reset link. Check your inbox (and storage/mail/ on this server).',
                ]);
                echo '<a class="btn btn-primary" href="'
                   . $this->view->e($this->url->to('user/login')) . '">Back to sign in</a>';
                return;
            }

            if ($error !== null) {
                echo $this->form->alert(['type' => 'danger', 'message' => $error]);
            }

            echo '<p class="text-muted">Enter the email on your account and we\'ll send a reset link if it\'s active.</p>';

            echo $this->form->open([
                'action' => $this->url->to('user/password/forgot'),
                'method' => 'POST',
            ]);
            echo $this->form->field([
                'name'         => 'email',
                'label'        => 'Email address',
                'type'         => 'email',
                'value'        => $email,
                'autocomplete' => 'email',
            ]);
            echo '<button type="submit" class="btn btn-primary">Send reset link</button> ';
            echo '<a class="btn btn-outline-secondary" href="'
               . $this->view->e($this->url->to('user/login')) . '">Cancel</a>';
            echo $this->form->close();
        });
    }

    public function resetAction(): void
    {
        if ($this->auth->check()) {
            redirect_to('user');
            return;
        }

        $token = trim((string) (
            $this->request->get('token', 'string', '')
            ?: $this->request->post('token', 'string', '')
        ));
        $error = null;
        $done = false;

        $valid = $token !== '' ? $this->resets->findValidToken($token) : null;
        if ($token === '' || $valid === null) {
            $error = 'This reset link is invalid or has expired.';
        }

        if ($this->request->isPost() && $error === null) {
            try {
                $this->request->checkToken();
                $this->request->enforceRateLimit('user.password.reset', 10, 3600);
            } catch (\RuntimeException $e) {
                $error = $e->getCode() === 429
                    ? 'Too many attempts. Please try again later.'
                    : 'Your session expired. Please reload and try again.';
            }

            $password = (string) $this->request->post('password', 'raw', '');
            $confirm  = (string) $this->request->post('password_confirm', 'raw', '');

            if ($error === null) {
                if ($password === '' || strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } else {
                    try {
                        $this->resets->resetPassword($token, $password);
                        $done = true;
                    } catch (\Throwable $e) {
                        $error = $e->getMessage();
                    }
                }
            }
        }

        echo $this->view->inline(function () use ($token, $error, $done, $valid) {
            $this->view->assign('title', 'Reset password');
            $this->view->block('right_sidebar', 'Login');

            echo '<h1 class="h3 mb-4">Reset password</h1>';

            if ($done) {
                echo $this->form->alert([
                    'type'    => 'success',
                    'message' => 'Password updated. You can sign in now.',
                ]);
                echo '<a class="btn btn-primary" href="'
                   . $this->view->e($this->url->to('user/login')) . '">Sign in</a>';
                return;
            }

            if ($error !== null && $valid === null) {
                echo $this->form->alert(['type' => 'danger', 'message' => $error]);
                echo '<a class="btn btn-outline-primary" href="'
                   . $this->view->e($this->url->to('user/password/forgot')) . '">Request a new link</a>';
                return;
            }

            if ($error !== null) {
                echo $this->form->alert(['type' => 'danger', 'message' => $error]);
            }

            echo $this->form->open([
                'action' => $this->url->to('user/password/reset'),
                'method' => 'POST',
            ]);
            echo '<input type="hidden" name="token" value="' . $this->view->e($token) . '">';
            echo $this->form->field([
                'name'         => 'password',
                'label'        => 'New password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
                'help'         => 'At least 8 characters.',
            ]);
            echo $this->form->field([
                'name'         => 'password_confirm',
                'label'        => 'Confirm new password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
            ]);
            echo '<button type="submit" class="btn btn-primary">Update password</button>';
            echo $this->form->close();
        });
    }
}
