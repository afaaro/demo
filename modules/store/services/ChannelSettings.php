<?php
declare(strict_types=1);

namespace Module\Store\Services;

/**
 * Resolve Integration Details toggles from channel.settings with config defaults.
 */
final class ChannelSettings
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $defaultsCache = null;

    public static function bool(Channel $channel, string $key, bool $fallback = false): bool
    {
        if (array_key_exists($key, $channel->settings)) {
            return !empty($channel->settings[$key]);
        }

        $default = self::configDefault($channel->type, $key);
        if ($default !== null) {
            return (bool) $default;
        }

        return $fallback;
    }

    public static function string(Channel $channel, string $key, string $fallback = ''): string
    {
        if (array_key_exists($key, $channel->settings)
            && is_scalar($channel->settings[$key])
        ) {
            return trim((string) $channel->settings[$key]);
        }

        $default = self::configDefault($channel->type, $key);
        if (is_scalar($default)) {
            return trim((string) $default);
        }

        return $fallback;
    }

    public static function int(Channel $channel, string $key, int $fallback = 0): int
    {
        if (array_key_exists($key, $channel->settings)
            && is_numeric($channel->settings[$key])
        ) {
            return (int) $channel->settings[$key];
        }

        $default = self::configDefault($channel->type, $key);
        if (is_numeric($default)) {
            return (int) $default;
        }

        return $fallback;
    }

    private static function configDefault(string $type, string $key): mixed
    {
        $defaults = self::defaultsForType($type);
        return $defaults[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaultsForType(string $type): array
    {
        $type = strtolower($type);
        if (self::$defaultsCache !== null && isset(self::$defaultsCache[$type])) {
            return self::$defaultsCache[$type];
        }

        if (self::$defaultsCache === null) {
            self::$defaultsCache = [];
        }

        $file = null;
        foreach (['marketplace', 'courier', 'webstore'] as $category) {
            $candidate = __DIR__ . '/channels/' . $category . '/' . $type . '/config.php';
            if (is_file($candidate)) {
                $file = $candidate;
                break;
            }
        }
        if ($file === null) {
            $legacy = __DIR__ . '/channels/' . $type . '/config.php';
            $file = is_file($legacy) ? $legacy : null;
        }

        $out = [];
        if ($file !== null) {
            $config = include $file;
            if (is_array($config) && is_array($config['settings'] ?? null)) {
                foreach ($config['settings'] as $name => $def) {
                    if (is_array($def) && array_key_exists('default', $def)) {
                        $out[(string) $name] = $def['default'];
                    }
                }
            }
        }

        return self::$defaultsCache[$type] = $out;
    }
}
