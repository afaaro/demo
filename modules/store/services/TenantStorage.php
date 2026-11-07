<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;

/**
 * Filesystem paths isolated per tenant: storage/store/{tenant_id}/...
 */
final class TenantStorage
{
    public function __construct(private string $baseDir) {}

    public static function fromBaseDir(?string $baseDir = null): self
    {
        $base = $baseDir ?? (defined('BASEDIR')
            ? BASEDIR . 'storage' . DIRECTORY_SEPARATOR . 'store'
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'store');
        return new self($base);
    }

    public function root(?int $tenantId = null): string
    {
        $tid = $this->resolveTenantId($tenantId);
        $path = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR . $tid;
        if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Cannot create tenant storage: ' . $path);
        }
        return $path;
    }

    /** Public-relative path for assets, e.g. storage/store/1/uploads/... */
    public function publicPrefix(?int $tenantId = null): string
    {
        $tid = $this->resolveTenantId($tenantId);
        return 'storage/store/' . $tid;
    }

    private function resolveTenantId(?int $tenantId): int
    {
        if ($tenantId !== null && $tenantId > 0) {
            return $tenantId;
        }
        $ctx = TenantContext::get()->id();
        if ($ctx !== null && $ctx > 0) {
            return $ctx;
        }
        // Defensive fallback — Action should have bound a tenant already.
        return 1;
    }

    /**
     * Resolve a stored relative path (may be legacy without tenant prefix).
     * Returns absolute filesystem path if it belongs to the current tenant.
     */
    public function absoluteSafe(string $relative): ?string
    {
        $relative = str_replace(['\\', '..'], ['/', ''], ltrim($relative, '/'));
        $tid = $this->resolveTenantId(null);

        // New layout: {tenantId}/...
        if (preg_match('#^(\d+)/#', $relative, $m)) {
            if ((int) $m[1] !== $tid) {
                return null;
            }
            $full = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            return is_file($full) ? $full : null;
        }

        // Legacy flat path — only allow if file exists under current tenant folder copy or base
        $tenantFull = $this->root($tid) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($tenantFull)) {
            return $tenantFull;
        }
        $legacy = rtrim($this->baseDir, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        return is_file($legacy) ? $legacy : null;
    }
}
