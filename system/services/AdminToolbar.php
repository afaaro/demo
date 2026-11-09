<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\Toolbar;

/**
 * Loads each module's helpers/toolbar.php into the shared Toolbar.
 * Shown when the user has any module.view / module permission / *.
 */
final class AdminToolbar
{
    public function __construct(private Toolbar $toolbar) {}

    public function populate(): void
    {
        $paths = array_values(get_modules());

        $this->toolbar->loadFromModules($paths, [
            '*' => function (array $item): bool {
                return $this->parentVisible($item);
            },
        ]);

        foreach ($this->toolbar->all() as $menu => $items) {
            $filtered = [];
            foreach ($items as $item) {
                $item = $this->filterItem($item);
                if ($item !== null) {
                    $filtered[] = $item;
                }
            }
            $this->toolbar->set($menu, $filtered);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function parentVisible(array $item): bool
    {
        $perm = isset($item['permission']) && is_string($item['permission'])
            ? $item['permission']
            : null;
        if ($perm === null || $perm === '') {
            return true;
        }
        if (User::canCurrent($perm)) {
            return true;
        }
        if (str_ends_with($perm, '.view')) {
            $module = substr($perm, 0, -strlen('.view'));
            // Staff gates only — not canModule() (that matches front-end perms).
            return $module !== '' && (
                User::canCurrent($module . '.*')
                || User::canCurrent($module . '.view')
                || User::canCurrent($module . '.admin')
            );
        }
        return false;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function filterItem(array $item): ?array
    {
        if (!$this->parentVisible($item)) {
            return null;
        }

        if (empty($item['children']) || !is_array($item['children'])) {
            return $item;
        }

        $children = [];
        $keptActions = 0;
        foreach ($item['children'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (($child['divider'] ?? false) === true || isset($child['header'])) {
                $children[] = $child;
                continue;
            }
            if ($this->childVisible($child)) {
                $children[] = $child;
                $keptActions++;
            }
        }
        $item['children'] = $children;
        // Drop parents that only kept dividers/headers (empty-looking dropdowns).
        if ($keptActions === 0) {
            return null;
        }
        return $item;
    }

    /**
     * @param array<string, mixed> $child
     */
    private function childVisible(array $child): bool
    {
        $perm = isset($child['permission']) && is_string($child['permission'])
            ? $child['permission']
            : null;
        if ($perm === null || $perm === '') {
            $route = '';
            if (isset($child['route']) && is_string($child['route'])) {
                $route = $child['route'];
            } elseif (isset($child['active']) && is_string($child['active'])) {
                $route = $child['active'];
            }
            $perm = PermissionCatalog::fromRoute($route);
        }
        if ($perm === null || $perm === '') {
            return true;
        }
        return User::canCurrent($perm);
    }
}
