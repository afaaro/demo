<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Ebay;

/**
 * Platform-owned eBay developer app (App ID / Cert ID / RuName).
 *
 * Same model as storefeeder.com: tenants only consent; the host holds the app keys.
 * Per-channel App ID/Cert/RuName are used only when the channel opts into a custom app
 * (settings.custom_ebay_app) with a complete keyset — otherwise platform keys win.
 */
final class EbayPlatformApp
{
    private const KEYS = ['client_id', 'client_secret', 'ru_name'];

    /**
     * @param array<string, mixed> $channelCreds
     * @return array<string, string>
     */
    public static function merge(
        array $channelCreds,
        bool $sandbox = false,
        bool $useCustomApp = false,
    ): array {
        $platform = self::credentials($sandbox);
        $channelApp = [];
        foreach (self::KEYS as $key) {
            $channelApp[$key] = trim((string) ($channelCreds[$key] ?? ''));
        }

        // Prefer platform app whenever it is configured, unless this channel
        // explicitly uses a complete custom keyset (avoids stale channel creds → 401).
        if (self::hasComplete($platform) && !($useCustomApp && self::hasComplete($channelApp))) {
            $app = $platform;
        } elseif (self::hasComplete($channelApp)) {
            $app = $channelApp;
        } else {
            $app = [
                'client_id'     => $channelApp['client_id'] !== '' ? $channelApp['client_id'] : ($platform['client_id'] ?? ''),
                'client_secret' => $channelApp['client_secret'] !== '' ? $channelApp['client_secret'] : ($platform['client_secret'] ?? ''),
                'ru_name'       => $channelApp['ru_name'] !== '' ? $channelApp['ru_name'] : ($platform['ru_name'] ?? ''),
            ];
        }

        $out = $app;
        foreach ($channelCreds as $k => $v) {
            $k = (string) $k;
            if (in_array($k, self::KEYS, true)) {
                continue;
            }
            $out[$k] = is_scalar($v) ? (string) $v : '';
        }
        return $out;
    }

    /**
     * @return array{client_id: string, client_secret: string, ru_name: string}
     */
    public static function credentials(bool $sandbox = false): array
    {
        $ebay = self::configRoot();
        if ($sandbox) {
            $sbx = self::stringMap($ebay['sandbox'] ?? null);
            // Never mix production keys with the sandbox token URL.
            return [
                'client_id'     => $sbx['client_id'] ?? '',
                'client_secret' => $sbx['client_secret'] ?? '',
                'ru_name'       => $sbx['ru_name'] ?? '',
            ];
        }

        $src = self::stringMap($ebay);
        return [
            'client_id'     => $src['client_id'] ?? '',
            'client_secret' => $src['client_secret'] ?? '',
            'ru_name'       => $src['ru_name'] ?? '',
        ];
    }

    public static function isConfigured(bool $sandbox = false): bool
    {
        return self::hasComplete(self::credentials($sandbox));
    }

    /** True when platform keys can power Connect without per-channel App ID/Cert/RuName. */
    public static function managesChannelOAuth(bool $sandbox = false): bool
    {
        return self::isConfigured($sandbox);
    }

    /**
     * @param array<string, string> $creds
     */
    private static function hasComplete(array $creds): bool
    {
        foreach (self::KEYS as $key) {
            if (trim($creds[$key] ?? '') === '') {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private static function configRoot(): array
    {
        try {
            $config = \System\Engine\Registry::getInstance()->get('config');
            $raw = $config->get('storefeeder.ebay', []);
            return is_array($raw) ? $raw : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_array($v)) {
                continue;
            }
            $out[(string) $k] = trim((string) $v);
        }
        return $out;
    }
}
