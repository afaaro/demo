<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use Module\StoreFeeder\Services\Channels\ChannelClientInterface;
use RuntimeException;

final class ChannelClientFactory
{
    /** @var array<string, array>|null */
    private ?array $typesCache = null;

    public function __construct(
        private CredentialsVault  $vault,
        private ChannelRepository $channels,
    ) {}

    public function make(Channel $channel): ChannelClientInterface
    {
        $type  = strtolower($channel->type);
        $types = $this->availableTypes();

        if (!isset($types[$type])) {
            throw new RuntimeException(
                "Channel type '{$channel->type}' is not supported."
            );
        }

        $config = $types[$type];
        $class  = $config['client'] ?? null;

        if (!is_string($class) || !class_exists($class)) {
            throw new RuntimeException(
                "Channel type '{$channel->type}' has no valid client class."
            );
        }

        $creds = $this->vault->open($channel->credentials);
        if ($type === 'ebay') {
            $sandbox = !empty($channel->settings['sandbox']);
            $creds = \Module\StoreFeeder\Services\Channels\Ebay\EbayPlatformApp::merge(
                is_array($creds) ? $creds : [],
                $sandbox,
                !empty($channel->settings['custom_ebay_app']),
            );
        } elseif ($type === 'amazon') {
            $creds = \Module\StoreFeeder\Services\Channels\Amazon\AmazonPlatformApp::merge(
                is_array($creds) ? $creds : [],
                !empty($channel->settings['custom_amazon_app']),
            );
        }

        /** @var ChannelClientInterface $client */
        $client = new $class(
            $channel,
            $creds,
            $this->vault,
            $this->channels,
        );

        return $client;
    }

    public function categoryFor(Channel $channel): string
    {
        $types = $this->availableTypes();
        return (string) ($types[strtolower($channel->type)]['category'] ?? 'other');
    }

    public function isCourier(Channel $channel): bool
    {
        return $this->categoryFor($channel) === 'courier';
    }

    /** Marketplace / webstore channels that sync orders & inventory. */
    public function isSalesChannel(Channel $channel): bool
    {
        return in_array($this->categoryFor($channel), ['marketplace', 'webstore'], true);
    }

    /**
     * @return array<string, array>
     */
    public function availableTypes(): array
    {
        if ($this->typesCache !== null) {
            return $this->typesCache;
        }

        $base = __DIR__ . '/channels';
        $out  = [];

        if (!is_dir($base)) {
            return $this->typesCache = $out;
        }

        $entries = scandir($base) ?: [];
        sort($entries, SORT_STRING | SORT_FLAG_CASE);

        foreach ($entries as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $dirPath = $base . '/' . $dir;
            if (!is_dir($dirPath)) {
                continue;
            }

            $configFile = $dirPath . '/config.php';
            if (!is_file($configFile)) {
                continue;
            }

            $config = include $configFile;
            if (!is_array($config)) {
                continue;
            }

            // Prefer an explicit 'type' key from the config; fall back to
            // the directory name so existing folders keep working.
            $key = strtolower((string) ($config['type'] ?? $dir));

            if (isset($out[$key])) {
                throw new RuntimeException(
                    "Duplicate channel type '{$key}' (folder '{$dir}')."
                );
            }

            $out[$key] = $config;
        }

        return $this->typesCache = $out;
    }
}