<?php
declare(strict_types=1);

namespace System\Library;

final class AdminNav
{
    /** @var array<string, array> groupCode => definition */
    private array $groups = [];

    /** @var array<int, string> group codes in display order */
    private array $order = [];

    private bool $loaded = false;

    public function __construct(
        private Url    $url,
        private Config $config,
    ) {}

    public function load(): self
    {
        if ($this->loaded) return $this;
        $this->loaded = true;

        foreach (get_modules() as $moduleName => $modulePath) {
            if (!get_enabled_module($moduleName)) continue;
            
            $file = rtrim($modulePath, '/\\')
                  . DIRECTORY_SEPARATOR . 'helpers'
                  . DIRECTORY_SEPARATOR . 'sidebar.php';

            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $url    = $this->url;
            $config = $this->config;

            $groups = (static function () use ($file, $url, $config) {
                return include $file;
            })();

            if (!is_array($groups)) {
                error_log("[AdminNav] {$file} did not return an array.");
                continue;
            }

            foreach ($groups as $code => $def) {
                if (!is_string($code) || !is_array($def) || empty($def['items'])) {
                    continue;
                }

                // Default section: 'modules'
                $def['section'] = (string) ($def['section'] ?? 'modules');

                $this->groups[$code] = $def;
                if (!in_array($code, $this->order, true)) {
                    $this->order[] = $code;
                }
            }
        }

        return $this;
    }

    /** @param array<int, string> $codes */
    public function order(array $codes): self
    {
        $this->order = array_values(array_unique($codes));
        return $this;
    }

    /**
     * @return array<int, array>  ordered list of groups
     */
    public function groups(): array
    {
        $out = [];
        $seen = [];

        foreach ($this->order as $code) {
            if (isset($this->groups[$code]) && !isset($seen[$code])) {
                $out[] = ['code' => $code] + $this->groups[$code];
                $seen[$code] = true;
            }
        }

        foreach ($this->groups as $code => $def) {
            if (!isset($seen[$code])) {
                $out[] = ['code' => $code] + $def;
                $seen[$code] = true;
            }
        }

        return $out;
    }

    public function render(callable $can): string
    {
        $current = $this->currentPath();

        // Group by section, preserving order.
        $sections = [];
        foreach ($this->groups() as $group) {
            $section = (string) ($group['section'] ?? 'modules');
            $sections[$section][] = $group;
        }

        // Section metadata — icon + label. Order in this array is the
        // preferred display order.
        $sectionMeta = [
            'system'  => ['icon' => 'gear',       'label' => 'System'],
            'tools'   => ['icon' => 'wrench',     'label' => 'Tools'],
            'content' => ['icon' => 'file-text',  'label' => 'Content'],
            'modules' => ['icon' => 'box-seam',   'label' => 'Modules (installed)'],
        ];

        // Order: known sections first, in the order declared above,
        // then any unknown sections in the order they were registered.
        $sectionOrder = [];
        foreach (array_keys($sectionMeta) as $name) {
            if (isset($sections[$name])) {
                $sectionOrder[] = $name;
            }
        }
        foreach ($sections as $name => $_) {
            if (!in_array($name, $sectionOrder, true)) {
                $sectionOrder[] = $name;
            }
        }

        $html = '<nav class="admin-sidebar__nav">';

        foreach ($sectionOrder as $sectionName) {
            $meta = $sectionMeta[$sectionName] ?? [
                'icon'  => 'folder',
                'label' => ucfirst($sectionName),
            ];

            $html .= '<div class="admin-nav-section">';
            $html .= '<div class="admin-nav-section-label">'
                . '<i class="bi bi-' . $this->e($meta['icon']) . '"></i>'
                . '<span>' . $this->e($meta['label']) . '</span>'
                . '</div>';

            foreach ($sections[$sectionName] as $group) {
                $items = $this->filterVisible($group['items'], $can);
                if ($items === []) {
                    continue;
                }

                // Determine whether any item in this group is active.
                // If so, we expand the group by default.
                $groupActive = false;
                foreach ($items as $item) {
                    if ($this->isActive($item, $current)) {
                        $groupActive = true;
                        break;
                    }
                    // One level of nesting — check children too.
                    foreach ($item['items'] ?? [] as $child) {
                        if ($this->isActive($child, $current)) {
                            $groupActive = true;
                            break 2;
                        }
                    }
                }

                $collapseId = 'group-' . md5((string) $group['code']);
                $showClass  = $groupActive ? 'show' : '';
                $expanded   = $groupActive ? 'true' : 'false';

                $html .= '<div class="admin-nav-group">';

                if (!empty($group['label'])) {
                    $icon = !empty($group['icon'])
                        ? '<i class="bi bi-' . $this->e($group['icon']) . '"></i>'
                        : '';

                    $html .= '<button type="button"'
                        . ' class="admin-nav-group__label admin-nav-group__toggle"'
                        . ' data-bs-toggle="collapse"'
                        . ' data-bs-target="#' . $collapseId . '"'
                        . ' aria-expanded="' . $expanded . '">'
                        . $icon
                        . '<span>' . $this->e($group['label']) . '</span>'
                        . '<i class="bi bi-chevron-down ms-auto admin-nav-caret"></i>'
                        . '</button>';
                }

                $html .= '<div class="collapse ' . $showClass . '" id="' . $collapseId . '">';
                $html .= '<div class="admin-nav-group__body">';

                foreach ($items as $item) {
                    $html .= $this->renderItem($item, $current, (string) $group['code']);
                }

                $html .= '</div></div></div>';
            }

            $html .= '</div>';   // .admin-nav-section
        }

        $html .= '</nav>';
        $html .= <<<'HTML'
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const KEY = 'adminNavCollapsed';
            const collapsed = JSON.parse(localStorage.getItem(KEY) || '[]');

            // Restore state: collapse anything in the saved list, expand anything not.
            document.querySelectorAll('.admin-nav-group [data-bs-toggle="collapse"]').forEach(function (btn) {
                const target = btn.dataset.bsTarget;
                if (!target) return;

                const el = document.querySelector(target);
                if (!el) return;

                const bsCollapse = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });

                if (collapsed.includes(target)) {
                    bsCollapse.hide();
                } else if (!el.classList.contains('show')) {
                    // Leave default state as-is unless user explicitly toggled before
                }

                // Save on toggle
                el.addEventListener('hidden.bs.collapse', function () {
                    const list = JSON.parse(localStorage.getItem(KEY) || '[]');
                    if (!list.includes(target)) list.push(target);
                    localStorage.setItem(KEY, JSON.stringify(list));
                });

                el.addEventListener('shown.bs.collapse', function () {
                    const list = JSON.parse(localStorage.getItem(KEY) || '[]');
                    const idx = list.indexOf(target);
                    if (idx !== -1) list.splice(idx, 1);
                    localStorage.setItem(KEY, JSON.stringify(list));
                });
            });

            // Skip groups that contain an active link
            if (el.querySelector('.admin-nav-link.is-active')) {
                bsCollapse.show();
                return;
            }
        });
        </script>
        HTML;
        return $html;
    }

    private function filterVisible(array $items, callable $can): array
    {
        $out = [];

        foreach ($items as $item) {
            if (($item['divider'] ?? false) === true) { $out[] = $item; continue; }
            if (isset($item['header'])) { $out[] = $item; continue; }

            // Optional group gate (e.g. platform-only nav items).
            if (!empty($item['groups']) && is_array($item['groups'])) {
                $inGroup = false;
                foreach ($item['groups'] as $groupName) {
                    if (!is_string($groupName) || $groupName === '') {
                        continue;
                    }
                    if (\System\Services\User::hasGroup($groupName)) {
                        $inGroup = true;
                        break;
                    }
                }
                if (!$inGroup) {
                    continue;
                }
            }

            // Hide links the user cannot open. Explicit permission wins;
            // otherwise derive the same module.controller.action key Action uses.
            $permission = $this->itemPermission($item);
            if ($permission !== null && !$can($permission)) {
                continue;
            }

            if (!empty($item['items']) && is_array($item['items'])) {
                $visible = $this->filterVisible($item['items'], $can);
                if ($visible === []) continue;
                $item['items'] = $visible;
            }

            $out[] = $item;
        }

        return $this->cleanDividers($out);
    }

    /**
     * Resolve the permission key for a sidebar item.
     *
     * Priority:
     *  1. explicit ['permission' => 'module.controller.action']
     *  2. derive from ['route' => '...'] or ['active' => '...'] via route map
     */
    private function itemPermission(array $item): ?string
    {
        if (isset($item['permission']) && is_string($item['permission']) && $item['permission'] !== '') {
            return $item['permission'];
        }

        $route = '';
        if (isset($item['route']) && is_string($item['route'])) {
            $route = $item['route'];
        } elseif (isset($item['active']) && is_string($item['active'])) {
            $route = $item['active'];
        }

        $route = trim($route, '/');
        if ($route === '') {
            return null;
        }

        return \System\Services\PermissionCatalog::fromRoute($route);
    }

    private function cleanDividers(array $items): array
    {
        $out = [];
        $pendingDivider = false;

        foreach ($items as $item) {
            if (($item['divider'] ?? false) === true) {
                $pendingDivider = !empty($out);
                continue;
            }
            if ($pendingDivider) {
                $out[] = ['divider' => true];
                $pendingDivider = false;
            }
            $out[] = $item;
        }

        return $out;
    }

    private function renderItem(array $item, string $current, string $groupCode): string
    {
        if (($item['divider'] ?? false) === true) {
            return '<hr class="admin-nav-divider">';
        }
        if (isset($item['header'])) {
            return '<div class="admin-nav-header">' . $this->e((string) $item['header']) . '</div>';
        }

        $label = $this->e((string) ($item['label'] ?? ''));
        $url   = $this->e((string) ($item['url'] ?? '#'));
        $icon  = !empty($item['icon'])
            ? '<i class="bi bi-' . $this->e($item['icon']) . '"></i>'
            : '<i class="bi bi-dot"></i>';

        $children = $item['items'] ?? [];

        if ($children === []) {
            $active = $this->isActive($item, $current);
            $class  = 'admin-nav-link' . ($active ? ' is-active' : '');
            return '<a href="' . $url . '" class="' . $class . '">'
                 . $icon . '<span>' . $label . '</span></a>';
        }

        // Parent with children — collapsible.
        $parentActive = false;
        foreach ($children as $child) {
            if ($this->isActive($child, $current)) { $parentActive = true; break; }
        }

        $collapseId = 'nav-' . md5($groupCode . '|' . ($item['label'] ?? '') . $url);
        $showClass  = $parentActive ? 'show' : '';
        $expanded   = $parentActive ? 'true' : 'false';

        $html  = '<div class="admin-nav-group-collapse">';
        $html .= '<button type="button"'
               . ' class="admin-nav-link admin-nav-toggle' . ($parentActive ? ' is-active' : '') . '"'
               . ' data-bs-toggle="collapse"'
               . ' data-bs-target="#' . $collapseId . '"'
               . ' aria-expanded="' . $expanded . '">'
               . $icon
               . '<span>' . $label . '</span>'
               . '<i class="bi bi-chevron-down ms-auto admin-nav-caret"></i>'
               . '</button>';
        $html .= '<div class="collapse ' . $showClass . '" id="' . $collapseId . '">';
        $html .= '<div class="admin-nav-submenu">';
        foreach ($children as $child) {
            $html .= $this->renderItem($child, $current, $groupCode);
        }
        $html .= '</div></div></div>';
        return $html;
    }

    private function isActive(array $item, string $current): bool
    {
        if (!empty($item['exact'])) {
            return $current === (string) ($item['active'] ?? '');
        }
        if (!empty($item['active'])) {
            return str_starts_with($current, (string) $item['active']);
        }
        if (!empty($item['url'])) {
            return $current === (string) $item['url'];
        }
        return false;
    }

    private function currentPath(): string
    {
        return trim((string) ($_GET['route'] ?? ''), '/');
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}