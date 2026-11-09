<?php
declare(strict_types=1);

namespace System\Services;

use System\Library\RouteMap;

/**
 * Flat permission keys for the group/user editors: {module}.view, {module}.*,
 * scanned controller actions, extras from helpers/permissions.php, and
 * explicit route-map permission overrides.
 */
final class PermissionCatalog
{
    /** @var array<string, string>|null perm => label */
    private static ?array $flat = null;

    /**
     * @return array<string, string>
     */
    public static function scan(): array
    {
        if (self::$flat !== null) {
            return self::$flat;
        }

        $permissions = [];

        foreach (get_modules() as $module => $modulePath) {
            $module = strtolower((string) $module);
            $permissions[$module . '.view'] = ucfirst($module) . ' — show in admin menu';
            $permissions[$module . '.*'] = ucfirst($module) . ' — all permissions';

            $extraFile = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR
                . 'helpers' . DIRECTORY_SEPARATOR . 'permissions.php';
            if (is_file($extraFile) && is_readable($extraFile)) {
                $extra = include $extraFile;
                if (is_array($extra)) {
                    foreach ($extra as $perm => $label) {
                        if (!is_string($perm) || $perm === '') {
                            continue;
                        }
                        $permissions[$perm] = is_string($label) && $label !== ''
                            ? $label
                            : self::labelFor($perm);
                    }
                }
            }

            foreach (self::controllerActions($module, (string) $modulePath) as $perm => $label) {
                $permissions[$perm] = $label;
            }

            foreach (self::explicitRoutePermissions((string) $modulePath) as $perm) {
                $permissions[$perm] ??= self::labelFor($perm);
            }
        }

        ksort($permissions);
        return self::$flat = $permissions;
    }

    /**
     * @return array<string, array<string, string>> module => perm => label
     */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::scan() as $perm => $label) {
            $module = explode('.', $perm, 2)[0];
            $grouped[$module][$perm] = $label;
        }
        ksort($grouped);
        return $grouped;
    }

    /**
     * Same key shape as Action::derivePermission.
     */
    public static function fromRoute(string $route): ?string
    {
        $route = trim($route, '/');
        if ($route === '') {
            return null;
        }

        $parts = array_values(array_filter(explode('/', $route), fn(string $p) => $p !== ''));
        $module = $parts[0] ?? '';
        if ($module === '' || !function_exists('get_module_path')) {
            return null;
        }

        $modulePath = get_module_path($module);
        if ($modulePath === null || !is_string($modulePath)) {
            return null;
        }

        $match = RouteMap::match($route, $modulePath);
        if ($match === null) {
            return self::fromControllerGuess($module, $parts);
        }

        [, $def] = $match;
        $explicit = $def['permission'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        if (empty($def['auth'])) {
            return null;
        }

        $controller = trim((string) ($def['controller'] ?? ''), '/');
        $action = (string) ($def['action'] ?? 'index');
        if ($controller === '') {
            return null;
        }

        return self::keyFromController($module, $controller, $action);
    }

    public static function labelFor(string $perm): string
    {
        return ucwords(str_replace(['.', '_', '-'], ' ', $perm));
    }

    private static function permSegment(string $value): string
    {
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }

    private static function keyFromController(string $module, string $controller, string $action): ?string
    {
        $moduleKey = self::permSegment($module);
        $actionKey = self::permSegment($action);
        if ($moduleKey === '' || $actionKey === '') {
            return null;
        }

        $ctrlParts = array_values(array_filter(explode('/', $controller), fn(string $p) => $p !== ''));
        if ($ctrlParts !== [] && self::permSegment($ctrlParts[0]) === $moduleKey) {
            array_shift($ctrlParts);
        }

        $controllerKey = implode('.', array_map(self::permSegment(...), $ctrlParts));

        return $controllerKey !== ''
            ? "{$moduleKey}.{$controllerKey}.{$actionKey}"
            : "{$moduleKey}.{$actionKey}";
    }

    /**
     * @param array<int, string> $parts
     */
    private static function fromControllerGuess(string $module, array $parts): ?string
    {
        $action = $parts !== [] ? (string) array_pop($parts) : 'index';
        $controller = implode('/', $parts);
        return self::keyFromController($module, $controller, $action);
    }

    /**
     * @return array<string, string>
     */
    private static function controllerActions(string $module, string $modulePath): array
    {
        $permissions = [];
        $controllerPath = rtrim($modulePath, '/\\') . DIRECTORY_SEPARATOR . 'controllers';
        if (!is_dir($controllerPath)) {
            return $permissions;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($controllerPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }

            $abs = str_replace('\\', '/', $file->getPathname());
            $controllersDir = str_replace('\\', '/', $controllerPath) . '/';
            if (!str_starts_with($abs, $controllersDir)) {
                continue;
            }

            $relative = preg_replace('/\.php$/', '', substr($abs, strlen($controllersDir))) ?? '';
            $controllerKey = strtolower(str_replace('/', '.', $relative));
            if ($controllerKey === '') {
                continue;
            }

            // Parse *Action methods from source — do not require_once controllers
            // (missing imports / side effects would break the groups editor).
            $src = @file_get_contents($file->getPathname());
            if ($src === false || $src === '') {
                continue;
            }
            if (!preg_match_all(
                '/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)Action\s*\(/',
                $src,
                $matches
            )) {
                continue;
            }

            foreach ($matches[1] as $actionBase) {
                $action = strtolower((string) $actionBase);
                if ($action === '') {
                    continue;
                }
                $perm = $module . '.' . $controllerKey . '.' . $action;
                $permissions[$perm] = self::labelFor($perm);
            }
        }

        return $permissions;
    }

    /**
     * @return list<string>
     */
    private static function explicitRoutePermissions(string $modulePath): array
    {
        $out = [];
        foreach (RouteMap::load($modulePath) as $def) {
            if (!is_array($def)) {
                continue;
            }
            if (!empty($def['auth']) && is_string($def['permission'] ?? null) && $def['permission'] !== '') {
                $out[] = $def['permission'];
            }
            if (!empty($def['permissions']) && is_array($def['permissions'])) {
                foreach ($def['permissions'] as $p) {
                    if (is_string($p) && $p !== '') {
                        $out[] = $p;
                    }
                }
            }
        }
        return $out;
    }
}
