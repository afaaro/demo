<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use System\Engine\ForbiddenException;
use System\Engine\Registry;
use Throwable;

/**
 * Per-request StoreFeeder setup called from the core router after auth checks.
 *
 * Binds TenantContext (token / membership / platform) and injects the admin
 * tenant switcher into the top bar.
 */
final class RequestBootstrap
{
    /** Soft-resolve only — webhooks and platform tenant-manager screens. */
    private const SOFT_PREFIXES = [
        'storefeeder/ebay/',
        'storefeeder/admin/tenants',
        'storefeeder/admin/account-deletion',
    ];

    public function __construct(private Registry $registry) {}

    public function boot(string $uri): void
    {
        $this->resolveTenant($uri);
        $this->injectTenantBar($uri);
    }

    private function resolveTenant(string $uri): void
    {
        $uriNorm = trim($uri, '/');
        foreach (self::SOFT_PREFIXES as $prefix) {
            if (str_starts_with($uriNorm, $prefix)) {
                try {
                    $this->resolver()->resolve();
                } catch (Throwable) {
                    TenantContext::get()->clear();
                }
                return;
            }
        }

        $resolver = $this->resolver();
        try {
            $result = $resolver->resolve();
        } catch (Throwable $e) {
            throw new ForbiddenException('Tenant resolution failed: ' . $e->getMessage());
        }

        $reason = (string) ($result['reason'] ?? '');
        if (empty($result['ok']) && in_array($reason, [
            'invalid_token', 'tenant_suspended', 'unauthenticated', 'no_membership',
        ], true)) {
            throw new ForbiddenException(match ($reason) {
                'invalid_token'    => 'Invalid or revoked tenant token.',
                'tenant_suspended' => 'This tenant is suspended.',
                'no_membership'    => 'Your account is not assigned to a StoreFeeder tenant.',
                default            => 'Authentication required.',
            });
        }

        if (empty($result['ok']) || !TenantContext::get()->has()) {
            if ($resolver->isPlatformOwner()) {
                try {
                    $result = $resolver->forceDefaultContext();
                } catch (Throwable $e) {
                    throw new ForbiddenException('Tenant resolution failed: ' . $e->getMessage());
                }
            }
        }

        if (empty($result['ok']) || !TenantContext::get()->has()) {
            $reason = (string) ($result['reason'] ?? 'forbidden');
            throw new ForbiddenException(match ($reason) {
                'no_membership' => 'Your account is not assigned to a StoreFeeder tenant.',
                'no_tenant'     => 'Default StoreFeeder tenant is missing.',
                default         => 'Your account is not assigned to a StoreFeeder tenant.',
            });
        }
    }

    private function injectTenantBar(string $uri): void
    {
        if (!defined('ADMIN_PANEL') || !ADMIN_PANEL) {
            return;
        }

        try {
            if (!TenantContext::get()->has()) {
                return;
            }

            $db = $this->registry->get('db');
            $tenants = new TenantRepository($db);
            $resolver = $this->resolver();
            $request = $this->registry->get('request');

            // Manage?id=N enters that tenant before the top bar is drawn.
            $uriNorm = trim($uri, '/');
            if ($resolver->isPlatformOwner() && $uriNorm === 'storefeeder/admin/tenants') {
                $manageId = (int) $request->get('id', 'int', 0);
                if ($manageId > 0) {
                    $resolver->selectTenantForUser($manageId);
                }
            }

            $html = TenantBar::render(
                $this->registry->get('view'),
                $resolver,
                $request,
                $tenants,
                $uri,
            );
            if ($html !== '') {
                $this->registry->get('view')->block('admin_topbar', $html);
            }
        } catch (Throwable) {
            // Never block admin pages on a chrome widget.
        }
    }

    private function resolver(): TenantResolver
    {
        $db = $this->registry->get('db');
        return new TenantResolver(
            $db,
            $this->registry->get('request'),
            $this->registry->get('session'),
            $this->registry->get('auth'),
            new TenantRepository($db),
            new TenantTokenRepository($db),
        );
    }
}
