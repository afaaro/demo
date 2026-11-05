<?php
declare(strict_types=1);

use System\Engine\Controller;
use System\Services\RegistrationGuard;

class UserRegister extends Controller
{
    protected function boot(): void
    {
        // Registration is public — no auth check.
    }

    public function indexAction(): void
    {
        if ($this->auth->check()) {
            redirect_to('user');
            return;
        }

        $guard = new RegistrationGuard($this->session, $this->request, $this->db, $this->config);

        if (!$guard->isRegistrationOpen()) {
            echo $this->view->inline(function () {
                $this->view->assign('title', 'Registration closed');
                echo '<h1 class="h3 mb-3">Registration closed</h1>';
                echo '<p class="text-muted">New accounts are not being accepted right now. '
                   . 'Please contact an administrator if you need access.</p>';
                echo '<a class="btn btn-primary" href="'
                   . $this->view->e($this->url->to('user/login')) . '">Sign in</a>';
            });
            return;
        }

        $errors = [];
        $posted = [
            'username'     => '',
            'email'        => '',
            'display_name' => '',
        ];
        $done = false;

        if ($this->request->isPost()) {
            try {
                $this->request->enforceRateLimit('user.register', 5, 3600);
            } catch (\RuntimeException) {
                $errors['_general'] = 'Too many registration attempts from this network. Please try again later.';
            }

            if (empty($errors)) {
                try {
                    $this->request->checkToken();
                } catch (\RuntimeException) {
                    $errors['_general'] = 'Your session expired. Please reload and try again.';
                }
            }

            $posted['username']     = trim((string) $this->request->post('username',     'string', ''));
            $posted['email']        = trim((string) $this->request->post('email',        'string', ''));
            $posted['display_name'] = trim((string) $this->request->post('display_name', 'string', ''));

            $password        = (string) $this->request->post('password',         'raw', '');
            $passwordConfirm = (string) $this->request->post('password_confirm', 'raw', '');
            $captchaAnswer   = trim((string) $this->request->post('captcha', 'string', ''));
            $jsProof         = trim((string) $this->request->post('js_proof', 'raw', ''));

            if (empty($errors)) {
                $errors = $this->request->validate([
                    'username'     => 'required|min:3|max:32|regex:/^[a-zA-Z0-9_]{3,32}$/',
                    'email'        => 'required|email',
                    'display_name' => 'max:64',
                ]);
            }

            if ($password === '') {
                $errors['password'] = 'Password is required.';
            } elseif (strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } elseif ($password !== $passwordConfirm) {
                $errors['password_confirm'] = 'Passwords do not match.';
            }

            if (empty($errors['_general'])) {
                foreach ($guard->validateSubmission($posted, $captchaAnswer, $jsProof) as $field => $message) {
                    $errors[$field] = $message;
                }
            }

            if (empty($errors)) {
                try {
                    $defaultGroup = trim((string) $this->config->get('user.default_group', 'users'));
                    if ($defaultGroup === '') {
                        $defaultGroup = 'users';
                    }
                    \System\Services\User::create([
                        'email'        => $posted['email'],
                        'username'     => $posted['username'],
                        'password'     => $password,
                        'display_name' => $posted['display_name'],
                        'status'       => 'pending',
                        'groups'       => [$defaultGroup],
                    ]);

                    $this->session->remove('reg_form_started_at');
                    $done = true;
                    $posted = [
                        'username'     => '',
                        'email'        => '',
                        'display_name' => '',
                    ];
                } catch (\InvalidArgumentException $e) {
                    $errors['_general'] = $e->getMessage();
                } catch (\RuntimeException $e) {
                    $errors['_general'] = $e->getMessage();
                }
            }
        }

        $challenge = $guard->prepareForm();

        echo $this->view->inline(function () use ($errors, $posted, $challenge, $done) {
            $this->view->assign('title', 'Create an Account');
            $this->form->setErrors($errors);
            $e = fn(string $s): string => $this->view->e($s);

            echo '<h1 class="h3 mb-4">Create an Account</h1>';

            if ($done) {
                echo $this->form->alert([
                    'type'    => 'success',
                    'message' => 'Account created and awaiting approval. An administrator must activate your account before you can sign in.',
                ]);
                echo '<a href="' . $e($this->url->to('user/login')) . '"'
                   . ' class="btn btn-primary">Back to sign in</a>';
                return;
            }

            if (!empty($errors['_general'])) {
                echo $this->form->alert([
                    'type'    => 'danger',
                    'message' => $errors['_general'],
                ]);
            }

            echo $this->form->open([
                'action'       => $this->url->to('user/register'),
                'method'       => 'POST',
                'autocomplete' => 'off',
                'attrs'        => ['id' => 'reg-form'],
            ]);

            // Honeypots
            foreach ($challenge['honeypots'] as $hp) {
                $hpE = $e((string) $hp);
                echo '<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">';
                echo '<label for="reg_' . $hpE . '">' . $hpE . '</label>';
                echo '<input type="text" name="' . $hpE . '" id="reg_' . $hpE
                   . '" value="" tabindex="-1" autocomplete="off">';
                echo '</div>';
            }

            echo '<input type="hidden" name="js_proof" id="reg-js-proof" value="">';

            echo $this->form->field([
                'name'         => 'username',
                'label'        => 'Username',
                'type'         => 'text',
                'value'        => $posted['username'],
                'autocomplete' => 'username',
                'help'         => '3–32 characters: letters, numbers, underscore. Include a number if the name is long.',
            ]);

            echo $this->form->field([
                'name'         => 'email',
                'label'        => 'Email address',
                'type'         => 'email',
                'value'        => $posted['email'],
                'autocomplete' => 'email',
            ]);

            echo $this->form->field([
                'name'         => 'display_name',
                'label'        => 'Display name',
                'type'         => 'text',
                'value'        => $posted['display_name'],
                'autocomplete' => 'name',
                'help'         => 'Optional. Shown in the header instead of your username.',
            ]);

            echo $this->form->field([
                'name'         => 'password',
                'label'        => 'Password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
                'help'         => 'At least 8 characters.',
            ]);

            echo $this->form->field([
                'name'         => 'password_confirm',
                'label'        => 'Confirm password',
                'type'         => 'password',
                'autocomplete' => 'new-password',
            ]);

            // Word challenge — not a trivial arithmetic parse.
            $word = $e((string) $challenge['captcha_word']);
            echo '<div class="mb-3">';
            echo '<label class="form-label" for="reg_captcha">'
               . $e((string) $challenge['captcha_prompt']) . '</label>';
            echo '<div class="border rounded px-3 py-2 mb-2 bg-light user-select-none"'
               . ' style="letter-spacing:.35em;font-size:1.35rem;font-weight:600;font-family:monospace">'
               . $word . '</div>';
            echo '<input type="text" name="captcha" id="reg_captcha" class="form-control"'
               . ' value="" autocomplete="off" autocapitalize="off" spellcheck="false" required>';
            echo '<div class="form-text">Type the word exactly as shown (required anti-spam check).</div>';
            if (!empty($errors['captcha'])) {
                echo '<div class="invalid-feedback d-block">' . $e((string) $errors['captcha']) . '</div>';
            }
            echo '</div>';

            echo '<button type="submit" class="btn btn-primary" id="reg-submit" disabled>Create account</button>';
            echo ' ';
            echo '<a href="' . $e($this->url->to('user/login')) . '"'
               . ' class="btn btn-outline-secondary">I already have an account</a>';
            echo '<p class="text-muted small mt-2 mb-0" id="reg-js-hint">Preparing form…</p>';

            echo $this->form->close();

            $tokenA = json_encode((string) $challenge['js_token_a'], JSON_HEX_TAG | JSON_HEX_AMP);
            $tokenB = json_encode((string) $challenge['js_token_b'], JSON_HEX_TAG | JSON_HEX_AMP);
            $minSec = (int) $challenge['min_seconds'];

            echo <<<HTML
<script>
(function () {
  var proof = document.getElementById('reg-js-proof');
  var btn = document.getElementById('reg-submit');
  var hint = document.getElementById('reg-js-hint');
  var form = document.getElementById('reg-form');
  if (!proof || !btn || !form) return;

  // Assemble proof client-side (bots that POST blindly fail).
  var a = {$tokenA};
  var b = {$tokenB};
  var readyAt = Date.now() + ({$minSec} * 1000);

  function unlock() {
    proof.value = String(a) + String(b);
    var wait = Math.max(0, readyAt - Date.now());
    setTimeout(function () {
      btn.disabled = false;
      if (hint) hint.textContent = '';
    }, wait);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', unlock);
  } else {
    unlock();
  }

  form.addEventListener('submit', function (ev) {
    if (!proof.value) {
      ev.preventDefault();
      alert('Please enable JavaScript to register.');
    }
  });
})();
</script>
HTML;
        });
    }
}
