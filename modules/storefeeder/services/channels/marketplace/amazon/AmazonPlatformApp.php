<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services\Channels\Marketplace\Amazon;

/**
 * Platform-owned Amazon SP-API / LWA app.
 *
 * Same model as eBay: tenants only consent; the host holds application_id + LWA keys.
 * Per-channel keys are used only when the channel opts into a custom app
 * (settings.custom_amazon_app) with a complete keyset.
 */
final class AmazonPlatformApp
{
    private const KEYS = ['application_id', 'client_id', 'client_secret'];

    /**
     * @param array<string, mixed> $channelCreds
     * @return array<string, string>
     */
    public static function merge(array $channelCreds, bool $useCustomApp = false): array
    {
        $platform = self::credentials();
        $channelApp = [];
        foreach (self::KEYS as $key) {
            $channelApp[$key] = trim((string) ($channelCreds[$key] ?? ''));
        }

        if (self::hasComplete($platform) && !($useCustomApp && self::hasComplete($channelApp))) {
            $app = $platform;
        } elseif (self::hasComplete($channelApp)) {
            $app = $channelApp;
        } else {
            $app = [
                'application_id' => $channelApp['application_id'] !== ''
                    ? $channelApp['application_id']
                    : ($platform['application_id'] ?? ''),
                'client_id' => $channelApp['client_id'] !== ''
                    ? $channelApp['client_id']
                    : ($platform['client_id'] ?? ''),
                'client_secret' => $channelApp['client_secret'] !== ''
                    ? $channelApp['client_secret']
                    : ($platform['client_secret'] ?? ''),
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
        if (!isset($out['redirect_uri']) || trim((string) $out['redirect_uri']) === '') {
            $out['redirect_uri'] = $platform['redirect_uri'] ?? '';
        }
        return $out;
    }

    /**
     * @return array{application_id: string, client_id: string, client_secret: string, redirect_uri: string}
     */
    public static function credentials(): array
    {
        $src = self::stringMap(self::configRoot());
        return [
            'application_id' => $src['application_id'] ?? '',
            'client_id'      => $src['client_id'] ?? '',
            'client_secret'  => $src['client_secret'] ?? '',
            'redirect_uri'   => $src['redirect_uri'] ?? '',
        ];
    }

    public static function isConfigured(): bool
    {
        return self::hasComplete(self::credentials());
    }

    public static function managesChannelOAuth(): bool
    {
        return self::isConfigured();
    }

    public static function isDraft(): bool
    {
        $root = self::configRoot();
        if (!array_key_exists('draft', $root)) {
            return true;
        }
        return !empty($root['draft']);
    }

    /**
     * @param array<string, mixed> $channelSettings
     */
    public static function redirectUri(array $channelSettings = []): string
    {
        $saved = trim((string) ($channelSettings['oauth_redirect_uri'] ?? ''));
        if ($saved !== '') {
            return $saved;
        }
        $fromConfig = trim(self::credentials()['redirect_uri'] ?? '');
        if ($fromConfig !== '') {
            return $fromConfig;
        }
        try {
            $url = \System\Engine\Registry::getInstance()->get('url');
            if (is_object($url) && method_exists($url, 'to')) {
                return (string) $url->to('storefeeder/admin/channels/callback', [], true);
            }
        } catch (\Throwable) {
        }
        return '';
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
            $raw = $config->get('storefeeder.amazon', []);
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
