<?php
declare(strict_types=1);

namespace Module\Store\Services;

use System\Library\Database;
use System\Library\Request;
use System\Library\Session;
use System\Services\User;

/**
 * Resolve tenant from Bearer / X-Tenant-Token, platform owner session, or membership.
 *
 * Model: platform owner is anyone with store.admin.tenants.index
 * (or store.*). Client orgs are tenants under them.
 */
final class TenantResolver
{
    public const SESSION_TENANT_KEY = 'store_tenant_id';

    public function __construct(
        private Database $db,
        private Request $request,
        private Session $session,
        private User $auth,
        private TenantRepository $tenants,
        private TenantTokenRepository $tokens,
    ) {}

    /**
     * @return array{ok:bool, reason?:string, via?:string}
     */
    public function resolve(): array
    {
        $ctx = TenantContext::get();
        $ctx->clear();

        $plain = $this->extractToken();
        if ($plain !== null) {
            $row = $this->tokens->findValidByPlainToken($plain);
            if ($row === null) {
                return ['ok' => false, 'reason' => 'invalid_token'];
            }
            if (($row['tenant_status'] ?? '') === 'suspended') {
                return ['ok' => false, 'reason' => 'tenant_suspended'];
            }
            $ctx->set(
                (int) $row['tenant_id'],
                (string) $row['slug'],
                (string) $row['tenant_name'],
                (string) $row['tenant_status'],
                'token',
                $row['scopes'] ?? ['*'],
                (int) $row['id'],
            );
            $this->tokens->touch((int) $row['id']);
            return ['ok' => true, 'via' => 'token'];
        }

        if (!$this->auth->check()) {
            return ['ok' => false, 'reason' => 'unauthenticated'];
        }

        $userId = (int) ($this->auth->id() ?? 0);

        // Platform owner (domain holder): may operate in any active tenant.
        if ($this->isPlatformOwner()) {
            return $this->resolvePlatformOwner();
        }

        // Tenant users: membership only — never fall onto platform home.
        $memberships = $this->tenants->membershipsForUser($userId);
        if ($memberships === []) {
            return ['ok' => false, 'reason' => 'no_membership'];
        }

        $selected = (int) $this->session->get(self::SESSION_TENANT_KEY, 0);
        $chosen = null;
        foreach ($memberships as $m) {
            if ($selected > 0 && (int) $m['tenant_id'] === $selected) {
                $chosen = $m;
                break;
            }
        }
        if ($chosen === null) {
            $chosen = $memberships[0];
            $this->session->set(self::SESSION_TENANT_KEY, (int) $chosen['tenant_id']);
        }

        if (($chosen['tenant_status'] ?? '') === 'suspended') {
            return ['ok' => false, 'reason' => 'tenant_suspended'];
        }

        $ctx->set(
            (int) $chosen['tenant_id'],
            (string) $chosen['slug'],
            (string) $chosen['tenant_name'],
            (string) $chosen['tenant_status'],
            'membership',
            ['*'],
        );
        return ['ok' => true, 'via' => 'membership'];
    }

    /**
     * Last-resort bind used by Action when resolve left context empty.
     * Platform → platform home. Others → membership only (never steal platform home).
     *
     * @return array{ok:bool, reason?:string, via?:string}
     */
    public function forceDefaultContext(): array
    {
        if (!$this->auth->check()) {
            return ['ok' => false, 'reason' => 'unauthenticated'];
        }
        if ($this->isPlatformOwner()) {
            return $this->bindPlatformHome('platform');
        }

        $userId = (int) ($this->auth->id() ?? 0);
        $memberships = $this->tenants->membershipsForUser($userId);
        if ($memberships === []) {
            return ['ok' => false, 'reason' => 'no_membership'];
        }
        $m = $memberships[0];
        if (($m['tenant_status'] ?? '') === 'suspended') {
            return ['ok' => false, 'reason' => 'tenant_suspended'];
        }
        TenantContext::get()->set(
            (int) $m['tenant_id'],
            (string) $m['slug'],
            (string) $m['tenant_name'],
            (string) $m['tenant_status'],
            'membership',
            ['*'],
        );
        $this->session->set(self::SESSION_TENANT_KEY, (int) $m['tenant_id']);
        return ['ok' => true, 'via' => 'membership'];
    }

    public function selectTenantForUser(int $tenantId): bool
    {
        $userId = (int) ($this->auth->id() ?? 0);
        if ($userId <= 0 || $tenantId <= 0) {
            return false;
        }

        if ($this->isPlatformOwner()) {
            $t = $this->tenants->find($tenantId);
            if ($t !== null && $t['status'] === 'active') {
                $this->bindTenantRow($t, 'platform');
                return true;
            }
            return false;
        }

        foreach ($this->tenants->membershipsForUser($userId) as $m) {
            if ((int) $m['tenant_id'] === $tenantId && ($m['tenant_status'] ?? '') === 'active') {
                $this->session->set(self::SESSION_TENANT_KEY, $tenantId);
                TenantContext::get()->set(
                    $tenantId,
                    (string) $m['slug'],
                    (string) $m['tenant_name'],
                    (string) $m['tenant_status'],
                    'membership',
                    ['*'],
                );
                return true;
            }
        }
        return false;
    }

    /**
     * Domain holder — may enter any tenant and see the full switcher.
     *
     * Driven by modules/store/config/app.php → platform_user_ids
     * (default [1]), not by store.* — otherwise every CMS Admin lands
     * on Default / platform home.
     */
    public function isPlatformOwner(): bool
    {
        if (!$this->auth->check()) {
            return false;
        }
        $uid = (int) ($this->auth->id() ?? 0);
        return $uid > 0 && in_array($uid, self::platformUserIds(), true);
    }

    /** @return list<int> */
    public static function platformUserIds(): array
    {
        $ids = [1];
        try {
            $config = \System\Engine\Registry::getInstance()->get('config');
            $raw = $config->get('store.platform_user_ids', [1]);
            if (is_string($raw) && $raw !== '') {
                $ids = array_map('intval', explode(',', $raw));
            } elseif (is_array($raw)) {
                $ids = array_map('intval', $raw);
            }
        } catch (\Throwable) {
            // keep default
        }
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id) => $id > 0)));
        return $ids !== [] ? $ids : [1];
    }

    /**
     * @return array{ok:bool, reason?:string, via?:string}
     */
    private function resolvePlatformOwner(): array
    {
        $selected = (int) $this->session->get(self::SESSION_TENANT_KEY, 0);
        if ($selected > 0) {
            $t = $this->tenants->find($selected);
            if ($t !== null && ($t['status'] ?? '') === 'active') {
                $this->bindTenantRow($t, 'platform');
                return ['ok' => true, 'via' => 'platform'];
            }
        }

        return $this->bindPlatformHome('platform');
    }

    /**
     * @return array{ok:bool, reason?:string, via?:string}
     */
    private function bindPlatformHome(string $via): array
    {
        try {
            $home = $this->tenants->ensurePlatformHome();
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'no_tenant'];
        }
        if (($home['status'] ?? '') !== 'active') {
            return ['ok' => false, 'reason' => 'tenant_suspended'];
        }

        $this->bindTenantRow($home, $via);
        return ['ok' => true, 'via' => $via];
    }

    /** @param array<string, mixed> $tenant */
    private function bindTenantRow(array $tenant, string $via): void
    {
        $this->session->set(self::SESSION_TENANT_KEY, (int) $tenant['id']);
        TenantContext::get()->set(
            (int) $tenant['id'],
            (string) $tenant['slug'],
            (string) $tenant['name'],
            (string) $tenant['status'],
            $via,
            ['*'],
        );
    }

    private function extractToken(): ?string
    {
        $header = trim((string) ($this->request->header('authorization') ?? ''));
        if (stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            if ($token !== '') {
                return $token;
            }
        }
        $x = trim((string) ($this->request->header('x-tenant-token') ?? ''));
        if ($x !== '') {
            return $x;
        }
        return null;
    }
}
