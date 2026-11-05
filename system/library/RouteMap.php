<?php
declare(strict_types=1);

namespace System\Library;

/**
 * Shared parser for a module's helpers/route.php map.
 *
 * The router (Action) and the URL builder (Url) both need to interpret the
 * same three definition shapes:
 *
 *   'news/view' => 'view'                    // action name
 *   'news/list' => ['id', 'page']            // positional param names
 *   'news/edit' => ['action' => 'edit', ...] // full definition
 *
 * Centralising this avoids the two classes drifting apart (FIX: U11).
 */
final class RouteMap
{
    /** @var array<string, array<string, array>> Cache: module path => normalised map */
    private static array $cache = [];

    /**
     * @return array<string, array{
     *     controller: ?string, action: string, params: array<int,string>,
     *     auth: bool, permission: ?string, permissions: array<int,string>,
     *     permission_mode: string, group: ?string, groups: array<int,string>,
     *     methods: array<int,string>, csrf: bool
     * }>
     */
    public static function load(string $modulePath): array
    {
        $real = realpath($modulePath);
        if ($real === false) {
            return [];
        }

        if (isset(self::$cache[$real])) {
            return self::$cache[$real];
        }

        $mapFile = $real . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'route.php';
        if (!is_readable($mapFile)) {
            return self::$cache[$real] = [];
        }

        $raw = include $mapFile;
        if (!is_array($raw)) {
            return self::$cache[$real] = [];
        }

        $maps = self::normalize($raw);

        // Longest pattern first so 'news/view' wins over 'news'
        uksort($maps, fn($a, $b) => strlen($b) <=> strlen($a));

        return self::$cache[$real] = $maps;
    }

    /**
     * @param array<int|string, mixed> $raw
     * @return array<string, array>
     */
    private static function normalize(array $raw): array
    {
        $out = [];

        foreach ($raw as $pattern => $def) {
            $key = trim((string) (is_int($pattern) && is_string($def) ? $def : $pattern), '/');
            if ($key === '') {
                continue;
            }

            if (is_string($def)) {
                $out[$key] = self::defaults(['action' => $def]);
            } elseif (array_is_list($def)) {
                $out[$key] = self::defaults(['params' => $def]);
            } elseif (is_array($def)) {
                $out[$key] = self::defaults($def);
            }
        }

        return $out;
    }

    private static function defaults(array $def): array
    {
        return $def + [
            'controller'      => null,
            'action'          => 'index',
            'params'          => [],
            'auth'            => false,
            'permission'      => null,
            'permissions'     => [],
            'permission_mode' => 'all',
            'group'           => null,
            'groups'          => [],
            'methods'         => ['GET', 'POST'],
            'csrf'            => true,
        ];
    }

    /**
     * Find the best (longest-prefix) match for a URI.
     *
     * @return array{0: string, 1: array}|null  [pattern, definition]
     */
    public static function match(string $uri, string $modulePath): ?array
    {
        $uri = trim($uri, '/');
        $bestPattern = null;
        $bestDef     = null;
        $bestLen     = -1;

        foreach (self::load($modulePath) as $pattern => $def) {
            $pattern = trim((string) $pattern, '/');
            if ($pattern === '') {
                continue;
            }
            if ($uri !== $pattern && !str_starts_with($uri, $pattern . '/')) {
                continue;
            }
            $len = strlen($pattern);
            if ($len > $bestLen) {
                $bestLen     = $len;
                $bestPattern = $pattern;
                $bestDef     = $def;
            }
        }

        return $bestPattern !== null && is_array($bestDef)
            ? [$bestPattern, $bestDef]
            : null;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}