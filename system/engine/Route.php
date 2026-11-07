<?php
declare(strict_types=1);

namespace System\Engine;

use InvalidArgumentException;

final class Route
{
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * @param array<string, string> $params
     * @param array<int, string>    $permissions
     * @param array<int, string>    $groups
     * @param array<int, string>    $methods
     */
    public function __construct(
        public readonly string  $module,
        public readonly string  $url,
        public readonly string  $pattern,
        public readonly string  $class,
        public readonly string  $file,
        public readonly string  $action         = 'index',
        public readonly array   $params         = [],
        public readonly bool    $auth           = false,
        public readonly ?string $permission     = null,
        public readonly array   $permissions    = [],
        public readonly string  $permissionMode = 'all',
        public readonly ?string $group          = null,
        public readonly array   $groups         = [],
        public readonly array   $methods        = ['GET'],
        public readonly bool    $csrf           = true,
    ) {
        if ($this->module === '' || $this->class === '' || $this->file === '') {
            throw new InvalidArgumentException('Route requires non-empty module, class and file.');
        }
        if (!in_array($this->permissionMode, ['all', 'any'], true)) {
            throw new InvalidArgumentException("Invalid permissionMode: {$this->permissionMode}");
        }
        foreach ($this->methods as $m) {
            if (!in_array(strtoupper($m), self::HTTP_METHODS, true)) {
                throw new InvalidArgumentException("Invalid HTTP method: {$m}");
            }
        }
    }

    public function isAdmin(): bool
    {
        return str_contains($this->url, '/admin/') || str_ends_with($this->url, '/admin');
    }

    public function allowsMethod(string $method): bool
    {
        $method = strtoupper($method);
        $allowed = array_map('strtoupper', $this->methods);

        if ($method === 'HEAD' && in_array('GET', $allowed, true)) {
            return true;
        }

        return in_array($method, $allowed, true);
    }
}
