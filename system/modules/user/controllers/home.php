<?php
declare(strict_types=1);

use System\Engine\Controller;

class UserHome extends Controller
{
    public function loginAction(): void
    {
        $next = $this->loginNextTarget();

        // Already signed in — honor referral when safe.
        if ($this->auth->check()) {
            $this->redirectAfterLogin($next);
            return;
        }

        // Keep referral across reloads / failed attempts.
        if ($next !== '') {
            $this->session->set('redirect_after_login', $next);
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
                            $this->session->remove('redirect_after_login');
                            $this->redirectAfterLogin($next);
                            return;
                        }
                    } else {
                        $error = 'Invalid username/email or password.';
                    }
                }
            }
        }

        echo $this->view->inline(function () use ($login, $remember, $error, $next) {
            $this->view->assign('title', 'Sign in');
            $this->view->block('right_sidebar', 'Login');
            $e = fn(string $s): string => $this->view->e($s);

            echo '<h1 class="h3 mb-4">Sign in</h1>';

            if ($error !== null) {
                echo $this->form->alert(['type' => 'danger', 'message' => $error]);
            }

            $actionParams = $next !== '' ? ['next' => $next] : [];
            echo $this->form->open([
                'action'       => $this->url->to('user/login', $actionParams),
                'method'       => 'POST',
                'autocomplete' => 'on',
            ]);

            if ($next !== '') {
                echo '<input type="hidden" name="next" value="' . $e($next) . '">';
            }

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
               . '<a href="' . $e($this->url->to('user/register', $actionParams)) . '">Register</a></p>';
        });
    }

    /**
     * Safe post-login target from POST/GET/session. Normalized route path only.
     */
    private function loginNextTarget(): string
    {
        $fromPost = $this->sanitizeLoginNext((string) $this->request->post('next', 'raw', ''));
        if ($fromPost !== '') {
            return $fromPost;
        }

        $fromQuery = $this->sanitizeLoginNext((string) $this->request->query('next', 'raw', ''));
        if ($fromQuery !== '') {
            // Call sites often pass next=news/view&slug=foo (slug separate).
            if (!str_contains($fromQuery, '?') && !$this->request->isPost()) {
                $fromQuery = $this->sanitizeLoginNext(
                    $this->appendRouteParams($fromQuery, $this->companionQueryParams())
                );
            }
            if ($fromQuery !== '') {
                return $fromQuery;
            }
        }

        return $this->sanitizeLoginNext((string) $this->session->get('redirect_after_login', ''));
    }

    /**
     * @return array<string, string>
     */
    private function companionQueryParams(): array
    {
        $keys = ['id', 'slug', 'u', 'page', 'category', 'tag', 'format', 'q', 'category_slug'];
        $extra = [];
        foreach ($keys as $key) {
            $val = $this->request->query($key, 'raw', null);
            if ($val !== null && $val !== '' && is_scalar($val)) {
                $extra[$key] = (string) $val;
            }
        }
        return $extra;
    }

    /**
     * Attach params as path segments when the route map declares them, else query string.
     *
     * @param array<string, string> $extra
     */
    private function appendRouteParams(string $route, array $extra): string
    {
        if ($extra === []) {
            return $route;
        }

        $path = $route;
        $query = [];
        if (str_contains($route, '?')) {
            [$path, $qs] = explode('?', $route, 2);
            parse_str($qs, $query);
            if (!is_array($query)) {
                $query = [];
            }
        }
        $path = trim($path, '/');

        $module = explode('/', $path)[0] ?? '';
        $modulePath = function_exists('get_module_path') ? get_module_path($module) : null;
        if (is_string($modulePath) && $modulePath !== '') {
            $match = \System\Library\RouteMap::match($path, $modulePath);
            if ($match !== null) {
                [$pattern, $def] = $match;
                $paramNames = array_values(array_filter(
                    is_array($def['params'] ?? null) ? $def['params'] : [],
                    'is_string'
                ));
                // Only embed when URI is exactly the pattern (no segments yet).
                $remainder = trim(substr($path, strlen($pattern)), '/');
                if ($remainder === '' && $paramNames !== []) {
                    $segments = [];
                    foreach ($paramNames as $name) {
                        if (!isset($extra[$name]) || $extra[$name] === '') {
                            break;
                        }
                        $segments[] = $extra[$name];
                        unset($extra[$name]);
                    }
                    if ($segments !== []) {
                        $path = rtrim($pattern, '/') . '/' . implode('/', $segments);
                    }
                }
            }
        }

        foreach ($extra as $k => $v) {
            $query[$k] = $v;
        }

        if ($query === []) {
            return $path;
        }
        return $path . '?' . http_build_query($query);
    }

    /**
     * Normalize full app URLs / index.php?route=… / path URIs into a route path.
     */
    private function sanitizeLoginNext(string $raw): string
    {
        $next = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
        $next = str_replace(["\r", "\n", "\0"], '', $next);
        if ($next === '' || str_contains($next, '..') || str_starts_with($next, '\\\\')) {
            return '';
        }

        // Same-host absolute URL → path + query only.
        if (preg_match('#^https?://#i', $next) === 1) {
            $parts = parse_url($next);
            if ($parts === false || empty($parts['host'])) {
                return '';
            }
            $targetHost = strtolower((string) $parts['host']);
            $reqHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $reqHost = preg_replace('/:\d+$/', '', $reqHost) ?? $reqHost;
            if ($targetHost === '' || $reqHost === '' || $targetHost !== $reqHost) {
                return '';
            }
            $next = (string) ($parts['path'] ?? '/');
            if (!empty($parts['query'])) {
                $next .= '?' . $parts['query'];
            }
        }

        if (str_starts_with($next, '//')) {
            return '';
        }

        // Strip app base path (/soomaali.net/… → …).
        $base = '/' . trim((string) $this->request->basePath(), '/');
        if ($base !== '/' && $base !== '') {
            if ($next === $base || $next === $base . '/') {
                return '';
            }
            if (str_starts_with($next, $base . '/')) {
                $next = substr($next, strlen($base));
            }
        }

        $next = ltrim($next, '/');
        if ($next === '') {
            return '';
        }

        // Non-SEO: index.php?route=forum/thread&id=3  (or route=forum%2Fthread%2F3)
        if (str_starts_with($next, 'index.php')) {
            $qs = str_contains($next, '?') ? substr($next, strpos($next, '?') + 1) : '';
            parse_str($qs, $q);
            if (!is_array($q)) {
                return '';
            }
            $route = trim(rawurldecode((string) ($q['route'] ?? '')), '/');
            unset($q['route']);
            if ($route === '') {
                return '';
            }
            $stringQ = [];
            foreach ($q as $k => $v) {
                if (is_string($k) && is_scalar($v) && (string) $v !== '') {
                    $stringQ[$k] = (string) $v;
                }
            }
            $next = $this->appendRouteParams($route, $stringQ);
        }

        if ($next === '' || !preg_match('#^[a-zA-Z0-9._/~-]+(?:\?[a-zA-Z0-9._&=%/-]*)?$#', $next)) {
            return '';
        }

        // Avoid login/register redirect loops.
        $pathOnly = explode('?', $next, 2)[0];
        if ($pathOnly === 'user/login' || $pathOnly === 'user/register'
            || str_starts_with($pathOnly, 'user/login/')
            || str_starts_with($pathOnly, 'user/register/')
        ) {
            return '';
        }

        return $next;
    }

    private function redirectAfterLogin(string $next): never
    {
        if ($next === '') {
            redirect_to('user');
        }

        $path = $next;
        $query = [];
        if (str_contains($next, '?')) {
            [$path, $qs] = explode('?', $next, 2);
            parse_str($qs, $query);
            if (!is_array($query)) {
                $query = [];
            }
        }

        $path = trim($path, '/');
        if ($path === '') {
            redirect_to('user');
        }

        // Build URL from the request path (forum/thread/3), not Url::to() strict maps.
        $base = '/' . trim((string) $this->request->basePath(), '/');
        if ($base === '/') {
            $base = '';
        }

        $seo = (bool) $this->config->get('app.seo.urls', false);
        if ($seo) {
            $url = $base . '/' . $path;
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }
        } else {
            $url = $base . '/index.php?route=' . rawurlencode($path);
            if ($query !== []) {
                $url .= '&' . http_build_query($query);
            }
        }

        redirect($url);
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
