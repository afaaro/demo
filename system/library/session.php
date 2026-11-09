<?php
declare(strict_types=1);

namespace System\Library;

use RuntimeException;

/**
 * Session
 *
 * Wraps PHP's native session with:
 *   - Namespaced storage (so modules/plugins don't collide)
 *   - Flash messages (survive exactly one request)
 *   - Old input + error bag (form re-population)
 *   - CSRF token integration with Request
 *   - Secure cookie params (HttpOnly, SameSite, Secure, Strict mode)
 *   - One-shot "reflash" so a redirect chain keeps messages alive
 *   - Idle timeout + fingerprint binding (UA hash)
 *   - Regeneration on privilege change (login, role change)
 */
class Session
{
    /** @var string Custom session cookie name. */
    private string $cookieName = 'afaaro';

    /** @var string Namespace prefix for keys owned by this class. */
    private string $namespace;

    /** @var int Idle timeout in seconds (0 = disabled). */
    private int $idleTimeout;

    /** @var bool Whether to bind the session to a fingerprint. */
    private bool $bindFingerprint;

    public const FLASH_KEY       = '_flash';
    private const FLASH_NEW_KEY  = '_flash_new';
    private const OLD_INPUT_KEY  = '_old';
    private const ERRORS_KEY     = '_errors';
    private const META_KEY       = '_meta';
    private const CSRF_KEY       = '_csrf_token';

    /**
     * @param array{
     *     cookie_name?: string,
     *     namespace?: string,
     *     idle_timeout?: int,
     *     bind_fingerprint?: bool,
     *     cookie_params?: array<string, mixed>,
     *     start?: bool
     * } $options
     */
    public function __construct(array $options = [])
    {
        $this->cookieName = preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            (string) ($options['cookie_name'] ?? 'afaaro')
        ) ?: 'afaaro';

        $this->namespace       = (string) ($options['namespace']       ?? 'afaaro');
        $this->idleTimeout     = (int)    ($options['idle_timeout']    ?? 1800);
        $this->bindFingerprint = (bool)   ($options['bind_fingerprint'] ?? true);

        if (($options['start'] ?? true) === true) {
            $this->start($options['cookie_params'] ?? []);
        }
    }

    // =========================================================================
    // Lifecycle
    // =========================================================================

    public function start(array $cookieParams = []): void
    {
        if ($this->isActive()) return;

        if (headers_sent($file, $line)) {
            throw new RuntimeException(
                "Cannot start session: headers already sent in {$file} on line {$line}."
            );
        }

        session_name($this->cookieName);

        $params = $cookieParams + [
            'lifetime' => 0,
            'path'     => $cookieParams['path'] ?? $this->detectBasePath(),
            'domain'   => '',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            $path = $params['path']
                  . (($params['samesite'] ?? '') !== '' ? '; samesite=' . $params['samesite'] : '');
            session_set_cookie_params(
                $params['lifetime'],
                $path,
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        if ($this->isHttps()) {
            ini_set('session.cookie_secure', '1');
        }

        session_start();

        $this->checkFingerprint();
        $this->checkIdleTimeout();
        $this->touch();
    }

    public function setLifetime(int $seconds): void
    {
        if (headers_sent()) {
            return;
        }

        session_name($this->cookieName);

        // Update the PHP-side params for the *next* session_start(),
        session_set_cookie_params(
            $seconds,
            $this->detectBasePath(),
            '',
            $this->isHttps(),
            true
        );

        // And, if a session is currently active, extend the current cookie.
        if ($this->isActive() && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();

            setcookie($this->cookieName, session_id(), [
                'expires'  => time() + $seconds,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

        $this->set('session_lifetime', $seconds);
    }

    public function destroy(): void
    {
        if (!$this->isActive()) {
            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();

            setcookie($this->cookieName, '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

        session_destroy();
    }

    public function getCookieName(): string
    {
        return session_name() ?: $this->cookieName;
    }

    /**
     * Compute the cookie path relative to DOCUMENT_ROOT.
     *
     * Returns '/' at the docroot, '/demo' for an app installed under /demo/,
     * etc. Never returns a path derived from the *name* of the docroot folder
     * (the previous basename(dirname(...)) approach was wrong for that case).
     */
    public function detectBasePath(): string
    {
        $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        $docRoot    = (string) ($_SERVER['DOCUMENT_ROOT']   ?? '');

        if ($scriptFile === '' || $docRoot === '') {
            return '/';
        }

        $scriptDir = str_replace('\\', '/', dirname($scriptFile));
        $docRoot   = rtrim(str_replace('\\', '/', $docRoot), '/');

        if (!str_starts_with($scriptDir, $docRoot)) {
            return '/';
        }

        $relative = trim(substr($scriptDir, strlen($docRoot)), '/');

        return $relative === '' ? '/' : '/' . $relative;
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function id(): string
    {
        return session_id() ?: '';
    }

    public function regenerate(bool $deleteOld = true): void
    {
        $this->ensureStarted();
        session_regenerate_id($deleteOld);
        $this->touch();
    }

    // =========================================================================
    // Namespaced get/set
    // =========================================================================

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$this->key($key)] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$this->key($key)] ?? $default;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return array_key_exists($this->key($key), $_SESSION);
    }

    public function remove(string ...$keys): void
    {
        $this->ensureStarted();
        foreach ($keys as $key) {
            unset($_SESSION[$this->key($key)]);
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->ensureStarted();
        $prefix = $this->key('');
        $out    = [];

        foreach ($_SESSION as $k => $v) {
            if (str_starts_with((string) $k, $prefix)) {
                $out[substr((string) $k, strlen($prefix))] = $v;
            }
        }
        return $out;
    }

    public function clear(): void
    {
        $this->ensureStarted();
        $prefix = $this->key('');

        foreach (array_keys($_SESSION) as $k) {
            if (str_starts_with((string) $k, $prefix)) {
                unset($_SESSION[$k]);
            }
        }
    }

    public function push(string $key, mixed $value): void
    {
        $arr   = $this->get($key, []);
        $arr   = is_array($arr) ? $arr : [];
        $arr[] = $value;
        $this->set($key, $arr);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    // =========================================================================
    // Flash messages
    // =========================================================================

    /**
     * Flash a message for the next request.
     *
     * @param string       $type    e.g. 'success', 'error', 'warning', 'info'
     * @param array<mixed> $context Optional extra data
     */
    public function flash(string $type, string $message, array $context = []): void
    {
        $flash = $this->get(self::FLASH_NEW_KEY, []);
        $flash = is_array($flash) ? $flash : [];

        $flash[] = [
            'type'    => $type,
            'message' => $message,
            'context' => $context,
        ];

        $this->set(self::FLASH_NEW_KEY, $flash);
    }

    /**
     * Return current + newly-flashed messages.
     *
     * Merging both bags means a flash() followed by getFlashes() in the same
     * request shows the message immediately, while the "next request" case
     * still works because ageFlash() promotes new → current.
     *
     * @return array<int, array{type:string,message:string,context:array}>
     */
    public function getFlashes(?string $type = null): array
    {
        $current = $this->get(self::FLASH_KEY, []);
        $new     = $this->get(self::FLASH_NEW_KEY, []);

        $flash = array_merge(
            is_array($current) ? $current : [],
            is_array($new)     ? $new     : []
        );

        if ($type === null) {
            return $flash;
        }

        return array_values(array_filter(
            $flash,
            fn($f) => ($f['type'] ?? null) === $type
        ));
    }

    /**
     * Return flashes and clear both bags so they are not shown again
     * after ageFlash() on the next request.
     *
     * @return array<int, array{type:string,message:string,context:array}>
     */
    public function pullFlashes(?string $type = null): array
    {
        $flash = $this->getFlashes($type);
        $this->clearFlashes();
        return $flash;
    }

    public function clearFlashes(): void
    {
        $this->remove(self::FLASH_KEY);
        $this->remove(self::FLASH_NEW_KEY);
    }

    public function hasFlashes(?string $type = null): bool
    {
        return !empty($this->getFlashes($type));
    }

    /**
     * Age the flash bag: new → current, drop the previous current.
     * Call once, at the end of every request (after rendering).
     *
     * Messages already shown via pullFlashes() leave both bags empty,
     * so nothing is promoted. Redirect-only flashes (never displayed)
     * stay in NEW and become CURRENT for the next request.
     */
    public function ageFlash(): void
    {
        $this->ensureStarted();

        $this->remove(self::FLASH_KEY);

        $new = $this->pull(self::FLASH_NEW_KEY, []);
        if (!empty($new)) {
            $this->set(self::FLASH_KEY, $new);
        }
    }

    /**
     * Keep the current flash for one more request.
     * Useful in a redirect chain (e.g. POST → GET → GET).
     */
    public function reflash(): void
    {
        $this->ensureStarted();
        $current = $this->get(self::FLASH_KEY, []);
        if (!empty($current)) {
            $this->set(self::FLASH_NEW_KEY, $current);
        }
    }

    // =========================================================================
    // Old input + error bag
    // =========================================================================

    /**
     * @param array<string, mixed> $input
     * @param array<int, string>   $except
     */
    public function flashInput(
        array $input,
        array $except = ['password', 'password_confirmation', '_csrf_token']
    ): void {
        $safe = array_diff_key($input, array_flip($except));
        $this->set(self::OLD_INPUT_KEY, $safe);
    }

    public function old(string $key, mixed $default = null): mixed
    {
        $old = $this->get(self::OLD_INPUT_KEY, []);
        return is_array($old) ? ($old[$key] ?? $default) : $default;
    }

    /** @return array<string, mixed> */
    public function allOld(): array
    {
        $old = $this->get(self::OLD_INPUT_KEY, []);
        return is_array($old) ? $old : [];
    }

    /** @param array<string, string> $errors field => message */
    public function flashErrors(array $errors): void
    {
        $this->set(self::ERRORS_KEY, $errors);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        $errors = $this->get(self::ERRORS_KEY, []);
        return is_array($errors) ? $errors : [];
    }

    public function error(string $field): ?string
    {
        return $this->errors()[$field] ?? null;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors());
    }

    /**
     * Clear the old input + error bag. Call at the end of every request.
     */
    public function ageInput(): void
    {
        $this->remove(self::OLD_INPUT_KEY, self::ERRORS_KEY);
    }

    // =========================================================================
    // CSRF (shares the same key as Request)
    // =========================================================================

    public function csrfToken(): string
    {
        $this->ensureStarted();

        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::CSRF_KEY];
    }

    public function regenerateCsrf(): string
    {
        $this->ensureStarted();
        return $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
    }

    public function validateCsrf(?string $submitted): bool
    {
        $this->ensureStarted();

        $stored = $_SESSION[self::CSRF_KEY] ?? '';
        if ($stored === '' || $submitted === null || $submitted === '') {
            return false;
        }
        return hash_equals($stored, (string) $submitted);
    }

    // =========================================================================
    // Idle timeout + fingerprint
    // =========================================================================

    private function touch(): void
    {
        $_SESSION[self::META_KEY] = [
            'last_activity' => time(),
            'fingerprint'   => $this->bindFingerprint ? $this->fingerprint() : null,
        ];
    }

    private function checkIdleTimeout(): void
    {
        if ($this->idleTimeout <= 0) {
            return;
        }

        $meta = $_SESSION[self::META_KEY] ?? null;
        if (!is_array($meta) || !isset($meta['last_activity'])) {
            return;
        }

        if ((time() - (int) $meta['last_activity']) > $this->idleTimeout) {
            // Regenerate in place — avoids two conflicting Set-Cookie headers.
            $_SESSION = [];
            session_regenerate_id(true);
            $this->touch();
        }
    }

    private function checkFingerprint(): void
    {
        if (!$this->bindFingerprint) {
            return;
        }

        $meta = $_SESSION[self::META_KEY] ?? null;
        if (!is_array($meta) || !isset($meta['fingerprint'])) {
            return;
        }

        if (!hash_equals((string) $meta['fingerprint'], $this->fingerprint())) {
            // Possible hijack — start a fresh session in place.
            $_SESSION = [];
            session_regenerate_id(true);
            $this->touch();
        }
    }

    private function fingerprint(): string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return hash('sha256', $ua);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    private function ensureStarted(): void
    {
        if (!$this->isActive()) {
            $this->start();
        }
    }

    private function key(string $key): string
    {
        return $this->namespace . ($key !== '' ? '.' . $key : '');
    }

    private function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https === 'on' || $https === '1') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // Only trust forwarded proto from local/private proxies (not arbitrary clients).
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remote !== '' && $this->isTrustedProxyAddr($remote)) {
            $fwd = strtolower(trim(
                explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]
            ));
            if ($fwd === 'https') {
                return true;
            }
        }

        return false;
    }

    private function isTrustedProxyAddr(string $ip): bool
    {
        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }
        // Private / link-local ranges commonly used by hosting reverse proxies.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}