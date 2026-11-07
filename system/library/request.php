<?php
declare(strict_types=1);

namespace System\Library;

use RuntimeException;

class Request
{
    /** @var string[] IPs allowed to set X-Forwarded-For / X-Real-IP */
    private array $trustedProxies = ['127.0.0.1', '::1'];

    private const CSRF_SESSION_KEY  = '_csrf_token';
    private const MAX_INPUT_LENGTH  = 65535;

    private const BOT_PATTERNS = [
        'bot', 'crawl', 'slurp', 'spider', 'mediapartners', 'google',
        'baidu', 'bing', 'yandex', 'duckduck', 'teoma', 'ia_archiver',
        'facebookexternalhit', 'twitterbot', 'rogerbot', 'linkedinbot',
        'embedly', 'showyoubot', 'outbrain', 'pinterest', 'developers.google',
        'quora link preview', 'slack', 'curl/', 'wget/', 'python-requests',
        'go-http-client', 'java/', 'okhttp', 'apache-httpclient',
    ];

    private array   $get;
    private array   $post;
    private array   $files;
    private array   $cookies;
    private array   $server;
    private array   $headers;
    private ?array  $jsonBody      = null;
    private bool    $jsonParsed    = false;
    private array   $routeParams   = [];
    private ?object $matchedRoute  = null;

    public function __construct(
        ?array $get    = null,
        ?array $post   = null,
        ?array $files  = null,
        ?array $cookie = null,
        ?array $server = null,
    ) {
        $this->get     = $get    ?? $_GET;
        $this->post    = $post   ?? $_POST;
        $this->files   = $files  ?? $_FILES;
        $this->cookies = $cookie ?? $_COOKIE;
        $this->server  = $server ?? $_SERVER;
        $this->headers = $this->parseHeaders();
    }

    // =========================================================================
    // Input access
    // =========================================================================

    /**
     * FIX: R2 — no longer mutates $_GET.
     * FIX: R5 — reads JSON body as a fallback so validate()/get() work for APIs.
     */
    public function get(string $key, string $type = 'string', mixed $default = null): mixed
    {
        $value = $this->get[$key]
              ?? $this->post[$key]
              ?? $this->routeParams[$key]
              ?? $this->json()[$key]
              ?? null;

        return $this->cast($value, $type, $default);
    }

    public function post(string $key, string $type = 'string', mixed $default = null): mixed
    {
        return $this->cast($this->post[$key] ?? null, $type, $default);
    }

    public function query(string $key, string $type = 'string', mixed $default = null): mixed
    {
        return $this->cast($this->get[$key] ?? null, $type, $default);
    }

    public function param(string $key, string $type = 'string', mixed $default = null): mixed
    {
        return $this->cast($this->routeParams[$key] ?? null, $type, $default);
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        $value = $this->cookies[$key] ?? null;
        return $value !== null ? $this->sanitizeString((string) $value) : $default;
    }

    /**
     * FIX: R13 — use `+` so keys are preserved (POST wins over GET).
     */
    public function all(array $keys = []): array
    {
        $merged = $this->post + $this->get;

        if (!empty($keys)) {
            $merged = array_intersect_key($merged, array_flip($keys));
        }

        return array_map(
            fn($v) => is_string($v) ? $this->sanitizeString($v) : $v,
            $merged
        );
    }

    public function only(string ...$keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key);
        }
        return $out;
    }

    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /** FIX: R1 — the missing accessor that Url::cleanRequest() needs. */
    public function currentRoute(): string
    {
        if ($this->matchedRoute !== null && isset($this->matchedRoute->url)) {
            return (string) $this->matchedRoute->url;
        }
        return (string) ($this->get['route'] ?? '');
    }

    // =========================================================================
    // File uploads
    // =========================================================================

    /**
     * FIX: R15 — non-OK upload errors return null instead of a fake entry.
     * FIX: R14 — do not trust $_FILES['type']; sniff with finfo when available.
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['tmp_name'], $file['error'])) {
            return null;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if (!is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return null;
        }

        return [
            'name'     => $this->sanitizeString(basename((string) ($file['name'] ?? ''))),
            'type'     => $this->detectMimeType($file['tmp_name']),
            'tmp_name' => $file['tmp_name'],
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) ($file['size'] ?? 0),
        ];
    }

    public function hasFile(string $key): bool
    {
        return $this->file($key) !== null;
    }

    private function detectMimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }
        return 'application/octet-stream';
    }

    // =========================================================================
    // Headers
    // =========================================================================

    /**
     * FIX: R12 — merge getallheaders() as a fallback for non-Apache SAPIs.
     */
    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($this->server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        $special = [
            'CONTENT_TYPE'   => 'content-type',
            'CONTENT_LENGTH' => 'content-length',
            'PHP_AUTH_USER'  => 'php-auth-user',
            'PHP_AUTH_PW'    => 'php-auth-pw',
        ];
        foreach ($special as $serverKey => $headerKey) {
            if (isset($this->server[$serverKey])) {
                $headers[$headerKey] = $this->server[$serverKey];
            }
        }

        if (function_exists('getallheaders')) {
            $all = getallheaders();
            if (is_array($all)) {
                foreach ($all as $name => $value) {
                    $headers[strtolower(str_replace('_', '-', $name))] = $value;
                }
            }
        }

        return $headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key   = strtolower(str_replace('_', '-', $name));
        $value = $this->headers[$key] ?? null;
        return $value !== null ? (string) $value : $default;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    // =========================================================================
    // JSON body
    // =========================================================================

    public function json(): ?array
    {
        if ($this->jsonParsed) {
            return $this->jsonBody;
        }

        $this->jsonParsed = true;

        $contentType = $this->headers['content-type'] ?? '';
        if (!str_contains($contentType, 'application/json') && !str_contains($contentType, '+json')) {
            return $this->jsonBody = null;
        }

        $raw = $this->rawBody();
        if (trim($raw) === '') {
            return $this->jsonBody = null;
        }

        $decoded = json_decode($raw, true);
        return $this->jsonBody = is_array($decoded) ? $decoded : null;
    }

    public function jsonGet(string $key, string $type = 'string', mixed $default = null): mixed
    {
        $json = $this->json();
        return $this->cast($json[$key] ?? null, $type, $default);
    }

    private function rawBody(): string
    {
        return (string) file_get_contents('php://input');
    }

    // =========================================================================
    // Request metadata
    // =========================================================================

    public function method(): string
    {
        if ($this->isPost()) {
            $override = strtoupper((string) ($this->post['_method'] ?? ''));
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function isPost(): bool
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? '')) === 'POST';
    }

    public function isAjax(): bool
    {
        $requestedWith = strtolower($this->headers['x-requested-with'] ?? '');
        $accept        = $this->headers['accept'] ?? '';
        $contentType   = $this->headers['content-type'] ?? '';

        return $requestedWith === 'xmlhttprequest'
            || str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json');
    }

    public function isJson(): bool
    {
        $contentType = $this->headers['content-type'] ?? '';
        return str_contains($contentType, 'application/json')
            || str_contains($contentType, '+json');
    }

    /**
     * FIX: R9 — only trust X-Forwarded-Proto when REMOTE_ADDR is a trusted proxy.
     */
    public function isSecure(): bool
    {
        $https     = strtolower((string) ($this->server['HTTPS'] ?? ''));
        $port      = (int) ($this->server['SERVER_PORT'] ?? 80);

        if ($https === 'on' || $https === '1' || $port === 443) {
            return true;
        }

        $remoteAddr = (string) ($this->server['REMOTE_ADDR'] ?? '');
        if (!in_array($remoteAddr, $this->trustedProxies, true)) {
            return false;
        }

        $forwarded = strtolower((string) ($this->headers['x-forwarded-proto'] ?? ''));
        $forwarded = trim(explode(',', $forwarded)[0]);
        return $forwarded === 'https';
    }

    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function path(): string
    {
        return strtok($this->uri(), '?') ?: '/';
    }

    public function basePath(): string
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptFile = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $docRoot    = $_SERVER['DOCUMENT_ROOT'] ?? '';

        if ($scriptName === '' || $scriptFile === '' || $docRoot === '') {
            return '';
        }

        $scriptDir = str_replace('\\', '/', dirname($scriptFile));
        $docRoot   = rtrim(str_replace('\\', '/', $docRoot), '/');

        if (!str_starts_with($scriptDir, $docRoot)) {
            return '';
        }

        $relative = substr($scriptDir, strlen($docRoot));
        return trim($relative, '/');
    }

    public function baseUrl(): string
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
            ? "https://" : "http://";

        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $basePath = $scriptName !== '' ? rtrim(dirname($scriptName), '/\\') : '';

        if ($basePath === '.' || $basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }

        if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] !== 80 && (int) $_SERVER['SERVER_PORT'] !== 443 && !str_contains($host, ':')) {
            $host .= ':' . (int) $_SERVER['SERVER_PORT'];
        }

        return $protocol . $host . ($basePath !== '' ? $basePath : '');
    }

    public function url(): string
    {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host   = (string) ($this->server['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host . $this->uri();
    }

    public function referer(): ?string
    {
        return $this->headers['referer'] ?? null;
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers['authorization'] ?? '';
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return null;
    }

    // =========================================================================
    // Route integration
    // =========================================================================

    /**
     * FIX: R2 — no longer mutates $_GET. Route params live on the request only.
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function setMatchedRoute(object $route): void
    {
        $this->matchedRoute = $route;
    }

    public function getMatchedRoute(): ?object
    {
        return $this->matchedRoute;
    }

    /** FIX: R1 — renamed for clarity; old getRoute($key) kept as a lookup. */
    public function getRoute(string $key, mixed $default = null): mixed
    {
        if ($this->matchedRoute === null) {
            return $default;
        }
        return $this->matchedRoute->{$key} ?? $default;
    }

    // =========================================================================
    // IP detection
    // =========================================================================

    public function ip(?array $trustedProxies = null): string
    {
        $proxies    = $trustedProxies ?? $this->trustedProxies;
        $remoteAddr = (string) ($this->server['REMOTE_ADDR'] ?? '');

        if (!in_array($remoteAddr, $proxies, true)) {
            return $this->normalizeIp($remoteAddr);
        }

        if (!empty($this->headers['x-forwarded-for'])) {
            $ips = array_map('trim', explode(',', $this->headers['x-forwarded-for']));
            for ($i = count($ips) - 1; $i >= 0; $i--) {
                $candidate = $ips[$i];
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false
                    && !in_array($candidate, $proxies, true)) {
                    return $this->normalizeIp($candidate);
                }
            }
        }

        foreach ([
            $this->headers['x-real-ip']        ?? null,
            $this->headers['cf-connecting-ip'] ?? null,
            $this->headers['true-client-ip']   ?? null,
        ] as $candidate) {
            if ($candidate === null) continue;
            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $this->normalizeIp($ip);
            }
        }

        return $this->normalizeIp($remoteAddr);
    }

    public function setTrustedProxies(array $proxies): void
    {
        $this->trustedProxies = $proxies;
    }

    // =========================================================================
    // Bot detection
    // =========================================================================

    public function isBot(): bool
    {
        $ua = strtolower($this->userAgent());
        if ($ua === '') {
            return true;
        }
        foreach (self::BOT_PATTERNS as $pattern) {
            if (str_contains($ua, $pattern)) {
                return true;
            }
        }
        return false;
    }

    // =========================================================================
    // CSRF protection
    // =========================================================================

    public function csrfToken(string $context = ''): string
    {
        $this->requireSession();

        $key = self::csrfSessionKey($context);

        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }
        return $_SESSION[$key];
    }

    /**
     * Validate the CSRF token for the given context.
     *
     * @param string $context  '' for the default token, or a name
     *                         (e.g. 'media') for a scoped token.
     */
    public function checkToken(string $context = ''): void
    {
        $this->requireSession();

        $key       = self::csrfSessionKey($context);
        $stored    = $_SESSION[$key] ?? '';
        $submitted = $this->readSubmittedCsrf($context);

        if ($stored === '' || !hash_equals($stored, (string) $submitted)) {
            throw new RuntimeException('CSRF token mismatch.', 419);
        }
    }

    public function verifyToken(string $context = ''): bool
    {
        $this->requireSession();

        $key       = self::csrfSessionKey($context);
        $stored    = $_SESSION[$key] ?? '';
        $submitted = $this->readSubmittedCsrf($context);

        return $stored !== '' && hash_equals($stored, (string) $submitted);
    }

    /**
     * Require a valid CSRF token. Accepts the default token or any scoped
     * token that was actually submitted (e.g. X-CSRF-Token-Media).
     */
    public function enforceCsrf(): void
    {
        foreach ($this->submittedCsrfContexts() as $context) {
            if ($this->verifyToken($context)) {
                return;
            }
        }

        throw new RuntimeException('CSRF token mismatch.', 419);
    }

    public function regenerateCsrf(string $context = ''): string
    {
        $this->requireSession();

        $key = self::csrfSessionKey($context);
        return $_SESSION[$key] = bin2hex(random_bytes(32));
    }

    public function csrfField(string $context = ''): string
    {
        $token = htmlspecialchars($this->csrfToken($context), ENT_QUOTES, 'UTF-8');
        $name  = self::csrfFieldName($context);

        return '<input type="hidden" name="' . $name . '" value="' . $token . '">';
    }

    // ---- Internals -----------------------------------------------------------

    private static function csrfSessionKey(string $context): string
    {
        return $context === ''
            ? self::CSRF_SESSION_KEY
            : self::CSRF_SESSION_KEY . '_' . $context;
    }

    private static function csrfFieldName(string $context): string
    {
        return $context === '' ? '_csrf_token' : '_csrf_token_' . $context;
    }

    /**
     * @return list<string>
     */
    private function submittedCsrfContexts(): array
    {
        $contexts = [''];

        foreach (array_keys($this->post) as $name) {
            $name = (string) $name;
            if (str_starts_with($name, '_csrf_token_')) {
                $ctx = substr($name, strlen('_csrf_token_'));
                if ($ctx !== '') {
                    $contexts[] = $ctx;
                }
            }
        }

        $json = $this->json();
        if (is_array($json)) {
            foreach (array_keys($json) as $name) {
                $name = (string) $name;
                if (str_starts_with($name, '_csrf_token_')) {
                    $ctx = substr($name, strlen('_csrf_token_'));
                    if ($ctx !== '') {
                        $contexts[] = $ctx;
                    }
                }
            }
        }

        foreach (array_keys($this->headers) as $header) {
            $h = strtolower((string) $header);
            if (str_starts_with($h, 'x-csrf-token-')) {
                $contexts[] = substr($h, strlen('x-csrf-token-'));
            } elseif (str_starts_with($h, 'x-xsrf-token-')) {
                $contexts[] = substr($h, strlen('x-xsrf-token-'));
            }
        }

        return array_values(array_unique($contexts));
    }

    private function readSubmittedCsrf(string $context): string
    {
        // 1. Form field (matches csrfField()'s output).
        $fieldName = self::csrfFieldName($context);
        if (isset($this->post[$fieldName])) {
            return (string) $this->post[$fieldName];
        }

        // 2. JSON body.
        $json = $this->json();
        if (is_array($json) && isset($json[$fieldName])) {
            return (string) $json[$fieldName];
        }

        // 3. Headers. Default context keeps the original names for compatibility.
        //    Named context uses a hyphenated suffix so JS can pick a distinct header.
        //    e.g. context 'media' → X-CSRF-Token-Media
        $suffix = $context === '' ? '' : '-' . $context;

        foreach ([
            'x-csrf-token' . $suffix,
            'x-xsrf-token' . $suffix,
        ] as $headerKey) {
            if (isset($this->headers[$headerKey])) {
                return (string) $this->headers[$headerKey];
            }
        }

        return '';
    }

    // =========================================================================
    // Rate limiting (APCu-backed)
    // =========================================================================

    public function enforceRateLimit(string $action, int $maxHits = 60, int $window = 60): void
    {
        $tenantPrefix = '';
        if (class_exists(\Module\Store\Services\TenantContext::class)) {
            $tid = \Module\Store\Services\TenantContext::get()->id();
            if ($tid !== null && $tid > 0) {
                $tenantPrefix = 't' . $tid . ':';
            }
        }
        $key = 'rl:' . $tenantPrefix . $action . ':' . $this->ip();

        if (function_exists('apcu_fetch')) {
            $hits = apcu_fetch($key, $ok);

            if (!$ok) {
                apcu_store($key, 1, $window);
                return;
            }

            if ((int) $hits >= $maxHits) {
                throw new RuntimeException(
                    "Rate limit exceeded for '{$action}'. Try again in {$window} seconds.",
                    429
                );
            }

            if (apcu_inc($key) === false) {
                apcu_store($key, (int) $hits + 1, $window);
            }
            return;
        }

        // Fallback: file-based counter in storage/cache/rate-limit/
        $this->fileRateLimit($key, $maxHits, $window, $action);
    }

    private function fileRateLimit(string $key, int $maxHits, int $window, string $action): void
    {
        $dir = (defined('BASEDIR') ? BASEDIR : sys_get_temp_dir() . DIRECTORY_SEPARATOR)
            . 'storage' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'rate-limit';

        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $safe = preg_replace('/[^a-z0-9._-]/i', '_', $key);
        $file = $dir . DIRECTORY_SEPARATOR . $safe . '.json';

        $data = ['hits' => 0, 'reset' => time() + $window];

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && ($decoded['reset'] ?? 0) > time()) {
                    $data = $decoded;
                }
            }
        }

        if ($data['hits'] >= $maxHits) {
            throw new RuntimeException(
                "Rate limit exceeded for '{$action}'. Try again in "
                . max(1, $data['reset'] - time()) . " seconds.",
                429
            );
        }

        $data['hits']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);
    }
    // =========================================================================
    // Input validation
    // =========================================================================

    /**
     * FIX: R5 — includes JSON body in the lookup chain.
     */
    public function validate(array $rules): array
    {
        $errors = [];
        $json   = $this->json() ?? [];

        foreach ($rules as $field => $ruleString) {
            $value = $this->get[$field]
                  ?? $this->post[$field]
                  ?? $this->routeParams[$field]
                  ?? $json[$field]
                  ?? null;

            foreach (array_map('trim', explode('|', (string) $ruleString)) as $rule) {
                if ($rule === '') continue;
                $error = $this->applyRule($field, $value, $rule);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }

        return $errors;
    }

    public function validateOrFail(array $rules): void
    {
        $errors = $this->validate($rules);
        if (!empty($errors)) {
            $message = implode('; ', array_map(
                fn($f, $e) => "{$f}: {$e}",
                array_keys($errors),
                $errors
            ));
            throw new RuntimeException("Validation failed: {$message}", 422);
        }
    }

    // =========================================================================
    // Sanitisation
    // =========================================================================

    public function sanitizeString(string $value): string
    {
        $value = str_replace("\0", '', $value);
        $value = mb_substr($value, 0, self::MAX_INPUT_LENGTH);
        return trim(strip_tags($value));
    }

    // =========================================================================
    // Private helpers — casting & validation
    // =========================================================================

    /**
     * FIX: R4 — strip NUL from $raw before typed checks so validated values
     * (email/url) can't smuggle a NUL byte downstream.
     */
    private function cast(mixed $value, string $type, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $raw = is_string($value)
            ? str_replace("\0", '', mb_substr($value, 0, self::MAX_INPUT_LENGTH))
            : $value;

        return match ($type) {
            'int'   => filter_var($raw, FILTER_VALIDATE_INT)   !== false ? (int)   $raw : $default,
            'float' => filter_var($raw, FILTER_VALIDATE_FLOAT) !== false ? (float) $raw : $default,
            'bool'  => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
            'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) !== false ? (string) $raw : $default,
            'url'   => filter_var($raw, FILTER_VALIDATE_URL)   !== false ? (string) $raw : $default,
            'alpha' => preg_match('/^[a-zA-Z]+$/', (string) $raw)    ? (string) $raw : $default,
            'alnum' => preg_match('/^[a-zA-Z0-9]+$/', (string) $raw) ? (string) $raw : $default,
            'slug'  => preg_match('/^[a-zA-Z0-9_-]+$/', (string) $raw) ? (string) $raw : $default,
            'raw'   => $raw,
            default => $this->sanitizeString((string) $raw),
        };
    }

    /**
     * FIX: R6 — validate regex delimiters before use; suppress warnings.
     */
    private function applyRule(string $field, mixed $value, string $rule): ?string
    {
        if (str_contains($rule, ':')) {
            [$ruleName, $ruleParam] = explode(':', $rule, 2);
        } else {
            $ruleName  = $rule;
            $ruleParam = '';
        }

        $strValue = (string) ($value ?? '');
        $isEmpty  = ($value === null || trim($strValue) === '');

        if ($ruleName === 'required') {
            return $isEmpty ? "{$field} is required." : null;
        }

        if ($isEmpty) {
            return null;
        }

        $len = mb_strlen($strValue);

        return match ($ruleName) {
            'int'   => filter_var($strValue, FILTER_VALIDATE_INT) === false
                ? "{$field} must be an integer." : null,

            'float' => filter_var($strValue, FILTER_VALIDATE_FLOAT) === false
                ? "{$field} must be a number." : null,

            'bool'  => filter_var($strValue, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null
                ? "{$field} must be true or false." : null,

            'email' => filter_var($strValue, FILTER_VALIDATE_EMAIL) === false
                ? "{$field} must be a valid email address." : null,

            'url'   => filter_var($strValue, FILTER_VALIDATE_URL) === false
                ? "{$field} must be a valid URL." : null,

            'alpha' => !preg_match('/^[a-zA-Z]+$/', $strValue)
                ? "{$field} may only contain letters." : null,

            'alnum' => !preg_match('/^[a-zA-Z0-9]+$/', $strValue)
                ? "{$field} may only contain letters and numbers." : null,

            'slug'  => !preg_match('/^[a-zA-Z0-9_-]+$/', $strValue)
                ? "{$field} may only contain letters, numbers, hyphens, and underscores." : null,

            'min' => $len < (int) $ruleParam
                ? "{$field} must be at least {$ruleParam} characters." : null,

            'max' => $len > (int) $ruleParam
                ? "{$field} must be no more than {$ruleParam} characters." : null,

            'num_min' => (float) $strValue < (float) $ruleParam
                ? "{$field} must be at least {$ruleParam}." : null,

            'num_max' => (float) $strValue > (float) $ruleParam
                ? "{$field} must be no more than {$ruleParam}." : null,

            'in' => !in_array($strValue, explode(',', $ruleParam), true)
                ? "{$field} must be one of: {$ruleParam}." : null,

            'regex' => $this->matchRegex($ruleParam, $strValue)
                ? null
                : "{$field} format is invalid.",

            default => null,
        };
    }

    private function matchRegex(string $pattern, string $subject): bool
    {
        // Reject empty or obviously malformed patterns without emitting warnings.
        if ($pattern === '' || strlen($pattern) < 2) {
            return false;
        }
        $delimiter = $pattern[0];
        if (ctype_alnum($delimiter) || $delimiter === '\\') {
            return false;
        }
        $result = @preg_match($pattern, $subject);
        return $result === 1;
    }

    // =========================================================================
    // Private helpers — IP
    // =========================================================================

    private function normalizeIp(string $ip): string
    {
        if (str_starts_with($ip, '::ffff:')) {
            $candidate = substr($ip, 7);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $candidate;
            }
        }
        return $ip;
    }

    // =========================================================================
    // Private helpers — session
    // =========================================================================

    private function requireSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            throw new RuntimeException(
                'A PHP session must be started before using CSRF protection. Call session_start() first.'
            );
        }
    }
}