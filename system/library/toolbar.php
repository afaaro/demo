<?php
declare(strict_types=1);

namespace System\Library;

use InvalidArgumentException;

final class Toolbar
{
    /**
     * @var array<string, array<int, array{
     *     label: string,
     *     url?: ?string,
     *     icon?: ?string,
     *     target?: ?string,
     *     class?: ?string,
     *     permission?: ?string,
     *     children?: array<int, array>
     * }>>
     */
    private array $menus = [];

    /** @var array<int, string> */
    private array $order = ['root', 'tools', 'users', 'config'];

    private bool $enabled = true;

    public function __construct(
        private Url    $url,
        private Config $config,
    ) {}

    // =========================================================================
    // Registration
    // =========================================================================

    /**
     * Add an item to a menu.
     *
     * An item is either:
     *   - a link:      has 'label' + 'url'
     *   - a dropdown:  has 'label' + non-empty 'children'
     *
     * Top-level dividers are ignored (dividers only make sense inside
     * a dropdown's `children` array).
     */
    public function add(string $menu, array $item): static
    {
        // Dividers are only valid inside a dropdown's children.
        if (($item['divider'] ?? false) === true) {
            return $this;
        }

        if (!isset($item['label'])) {
            throw new InvalidArgumentException(sprintf(
                "Toolbar item in menu '%s' requires a 'label' key. Got keys: %s",
                $menu,
                implode(', ', array_keys($item)) ?: '(none)'
            ));
        }

        $hasChildren = !empty($item['children']);

        if (!$hasChildren && !isset($item['url'])) {
            throw new InvalidArgumentException(sprintf(
                "Toolbar item '%s' in menu '%s' requires a 'url' key "
                . "(or a non-empty 'children' array).",
                $item['label'],
                $menu
            ));
        }

        $this->menus[$menu] ??= [];
        $this->menus[$menu][] = $item;

        return $this;
    }

    /** @param array<int, array> $items */
    public function addMany(string $menu, array $items): static
    {
        foreach ($items as $item) {
            $this->add($menu, $item);
        }
        return $this;
    }

    /** @param array<int, array> $items */
    public function set(string $menu, array $items): static
    {
        $this->menus[$menu] = [];
        return $this->addMany($menu, $items);
    }

    public function order(string $menu, int $position = 0): static
    {
        $this->order = array_values(array_diff($this->order, [$menu]));
        array_splice($this->order, $position, 0, [$menu]);
        return $this;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function has(string $menu): bool
    {
        return !empty($this->menus[$menu]);
    }

    /** @return array<int, array> */
    public function get(string $menu): array
    {
        return $this->menus[$menu] ?? [];
    }

    /** @return array<string, array<int, array>> */
    public function all(): array
    {
        return $this->menus;
    }

    public function clear(): static
    {
        $this->menus = [];
        return $this;
    }

    // =========================================================================
    // Module loading
    // =========================================================================

    /**
     * @param array<int, string>      $modulePaths
     * @param array<string, callable> $filters
     */
    public function loadFromModules(array $modulePaths, array $filters = []): static
    {
        foreach ($modulePaths as $modulePath) {
            $moduleDir = rtrim($modulePath, '/\\');

            if (!is_dir($moduleDir)) {
                error_log("[Toolbar] Module path does not exist: {$modulePath}");
                continue;
            }

            $file = $moduleDir . DIRECTORY_SEPARATOR
                  . 'helpers' . DIRECTORY_SEPARATOR
                  . 'toolbar.php';

            if (!is_file($file)) {
                continue;
            }

            if (!is_readable($file)) {
                error_log("[Toolbar] Toolbar file is not readable: {$file}");
                continue;
            }

            // Provide $url and $config as locals to the included file.
            $url    = $this->url;
            $config = $this->config;

            /** @var mixed $menu */
            $menu = (static function () use ($file, $url, $config) {
                return include $file;
            })();

            if (!is_array($menu)) {
                error_log("[Toolbar] {$file} did not return an array.");
                continue;
            }

            foreach ($menu as $menuName => $items) {
                if (!is_string($menuName) || !is_array($items)) {
                    continue;
                }

                $filter = $filters[$menuName] ?? $filters['*'] ?? null;

                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if ($filter !== null && !$filter($item)) {
                        continue;
                    }

                    try {
                        $this->add($menuName, $item);
                    } catch (InvalidArgumentException $e) {
                        throw new InvalidArgumentException(
                            $e->getMessage() . " (from {$file})",
                            0,
                            $e
                        );
                    }
                }
            }
        }

        return $this;
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    public function render(): string
    {
        if (!$this->enabled) {
            return '';
        }

        $menus = $this->orderedMenus();
        if ($menus === []) {
            return '';
        }

        $html  = '<div id="admin-toolbar" class="admin-toolbar" role="navigation" aria-label="Admin toolbar">';
        $html .= '<div class="admin-toolbar__inner container-fluid d-flex align-items-center gap-1">';

        // Home icon.
        $html .= '<a href="' . $this->e($this->url->to('')) . '" class="admin-toolbar__brand"'
               . ' title="Home" aria-label="Home">'
               . '<i class="bi bi-house-door-fill"></i></a>';

        foreach ($menus as $items) {
            foreach ($items as $item) {
                $html .= $this->renderItem($item);
            }
        }

        // Sign-out on the right.
        $html .= '<a href="' . $this->e($this->url->to('user/logout')) . '"'
               . ' class="admin-toolbar__link admin-toolbar__link--danger ms-auto"'
               . ' title="Sign out" aria-label="Sign out">'
               . '<i class="bi bi-box-arrow-right"></i>'
               . ' <span class="toolbar-label">Sign out</span></a>';

        $html .= '</div></div>';

        return $html;
    }

    /**
     * @param array{
     *     label: string, url?: ?string, icon?: ?string,
     *     target?: ?string, class?: ?string, children?: array
     * } $item
     */
    private function renderItem(array $item): string
    {
        $label  = $this->e($item['label']);
        $url    = isset($item['url']) ? $this->e($item['url']) : '#';
        $icon   = !empty($item['icon'])
            ? '<i class="bi bi-' . $this->e($item['icon']) . '"></i> '
            : '';
        $target = !empty($item['target'])
            ? ' target="' . $this->e($item['target']) . '" rel="noopener"'
            : '';
        $class  = !empty($item['class'])
            ? ' ' . $this->e($item['class'])
            : '';

        $children = $item['children'] ?? [];

        // No children → render a plain link.
        if (empty($children)) {
            return '<a href="' . $url . '"'
                 . ' class="admin-toolbar__link' . $class . '"'
                 . $target . '>'
                 . $icon . '<span class="toolbar-label">' . $label . '</span>'
                 . '</a>';
        }

        // Children → render a dropdown.
        // Fixed Popper strategy so menus aren't clipped by the toolbar's
        // overflow-x scroll container (especially on mobile/touch).
        $html  = '<div class="admin-toolbar__dropdown dropdown">';
        $html .= '<button type="button"'
               . ' class="admin-toolbar__link dropdown-toggle' . $class . '"'
               . ' data-bs-toggle="dropdown"'
               . ' data-bs-display="dynamic"'
               . ' data-bs-popper-config=\'{"strategy":"fixed"}\''
               . ' aria-expanded="false">'
               . $icon . '<span class="toolbar-label">' . $label . '</span>'
               . '</button>';
        $html .= '<ul class="dropdown-menu">';

        foreach ($children as $child) {
            if (!is_array($child)) continue;

            if (($child['divider'] ?? false) === true) {
                $html .= '<li><hr class="dropdown-divider"></li>';
                continue;
            }

            if (isset($child['header'])) {
                $html .= '<li><h6 class="dropdown-header">'
                    . $this->e($child['header'])
                    . '</h6></li>';
                continue;
            }

            $childLabel  = $this->e($child['label'] ?? '');
            $childUrl    = $this->e($child['url']   ?? '#');
            $childIcon   = !empty($child['icon'])
                ? '<i class="bi bi-' . $this->e($child['icon']) . ' me-2"></i>'
                : '';
            $childTarget = !empty($child['target'])
                ? ' target="' . $this->e($child['target']) . '" rel="noopener"'
                : '';

            $html .= '<li><a class="dropdown-item" href="' . $childUrl . '"'
                . $childTarget . '>'
                . $childIcon . $childLabel
                . '</a></li>';
        }

        $html .= '</ul></div>';

        return $html;
    }

    /** @return array<string, array<int, array>> */
    private function orderedMenus(): array
    {
        $out = [];

        foreach ($this->order as $name) {
            if (!empty($this->menus[$name])) {
                $out[$name] = $this->menus[$name];
            }
        }

        foreach ($this->menus as $name => $items) {
            if (!isset($out[$name]) && !empty($items)) {
                $out[$name] = $items;
            }
        }

        return $out;
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}