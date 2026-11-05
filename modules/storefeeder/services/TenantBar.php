<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use System\Library\Request;
use System\Library\View;

/**
 * Compact “working tenant” switcher for the admin top bar.
 *
 * Products, orders, and most StoreFeeder lists are scoped to this tenant.
 * Platform owners can enter any active tenant; other users only their memberships.
 */
final class TenantBar
{
    /** @var list<string> */
    private const STAY_ROUTES = [
        'storefeeder/admin',
        'storefeeder/admin/orders',
        'storefeeder/admin/returns',
        'storefeeder/admin/pickwaves',
        'storefeeder/admin/messages/conversations',
        'storefeeder/admin/cases',
        'storefeeder/admin/products',
        'storefeeder/admin/categories',
        'storefeeder/admin/brands',
        'storefeeder/admin/suppliers',
        'storefeeder/admin/warehouses',
        'storefeeder/admin/attributes',
        'storefeeder/admin/inventory',
        'storefeeder/admin/purchase-orders',
        'storefeeder/admin/channels',
        'storefeeder/admin/customers',
        'storefeeder/admin/tenants',
    ];

    public static function render(
        View $view,
        TenantResolver $resolver,
        Request $request,
        TenantRepository $tenants,
        string $currentUri = '',
    ): string {
        $ctx = TenantContext::get();
        if (!$ctx->has()) {
            return '';
        }

        $currentId = (int) $ctx->id();
        $options = self::options($resolver, $tenants);
        if ($options === []) {
            return '';
        }

        $next = self::safeNext($currentUri);
        $e = static fn(string|int|float $s): string => $view->e((string) $s);
        $home = false;
        foreach ($options as $opt) {
            if ((int) $opt['id'] === $currentId) {
                $home = !empty($opt['home']);
                break;
            }
        }

        ob_start();
        ?>
        <form method="post" action="<?= $e($view->url->to('storefeeder/admin/tenants/select')) ?>" class="sf-tenant-bar" title="StoreFeeder data is scoped to this tenant">
            <?= $request->csrfField() ?>
            <input type="hidden" name="next" value="<?= $e($next) ?>">
            <label class="sf-tenant-bar__label" for="sf-tenant-bar-select">
                <i class="bi bi-building"></i>
                <span>Working in</span>
            </label>
            <?php if (count($options) === 1): ?>
                <span class="sf-tenant-bar__name"><?= $e($ctx->name()) ?></span>
            <?php else: ?>
                <select name="tenant_id" id="sf-tenant-bar-select" class="sf-tenant-bar__select" onchange="this.form.submit()">
                    <?php foreach ($options as $opt): ?>
                        <option value="<?= (int) $opt['id'] ?>"<?= ((int) $opt['id'] === $currentId) ? ' selected' : '' ?>>
                            <?= $e($opt['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="sf-tenant-bar__btn">Switch</button>
            <?php endif; ?>
            <?php if ($home): ?>
                <span class="sf-tenant-bar__hint">platform home</span>
            <?php endif; ?>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    public static function safeNext(string $uri): string
    {
        $uri = strtolower(trim($uri, '/'));
        if ($uri === '' || !str_starts_with($uri, 'storefeeder/admin')) {
            return 'storefeeder/admin';
        }
        $best = 'storefeeder/admin';
        $bestLen = strlen($best);
        foreach (self::STAY_ROUTES as $route) {
            if ($uri === $route || str_starts_with($uri, $route . '/')) {
                if (strlen($route) >= $bestLen) {
                    $best = $route;
                    $bestLen = strlen($route);
                }
            }
        }
        return $best;
    }

    /**
     * @return list<array{id:int,label:string,home:bool}>
     */
    private static function options(TenantResolver $resolver, TenantRepository $tenants): array
    {
        $out = [];
        if ($resolver->isPlatformOwner()) {
            foreach ($tenants->all('active') as $t) {
                $home = $tenants->isPlatformHome($t);
                $label = (string) $t['name'];
                if ($home) {
                    $label .= ' — platform home';
                }
                $out[] = [
                    'id'    => (int) $t['id'],
                    'label' => $label,
                    'home'  => $home,
                ];
            }
            return $out;
        }

        $uid = (int) (\System\Services\User::id() ?? 0);
        foreach ($tenants->membershipsForUser($uid) as $m) {
            if (($m['tenant_status'] ?? '') !== 'active') {
                continue;
            }
            $out[] = [
                'id'    => (int) $m['tenant_id'],
                'label' => (string) $m['tenant_name'],
                'home'  => false,
            ];
        }
        return $out;
    }
}
