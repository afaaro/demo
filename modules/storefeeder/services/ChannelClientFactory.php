<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use Module\StoreFeeder\Services\Channels\ChannelClientInterface;
use RuntimeException;

final class ChannelClientFactory
{
    /** Category folders under services/channels/ that hold provider packages. */
    private const CATEGORY_DIRS = ['marketplace', 'courier', 'webstore'];

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
            $creds = \Module\StoreFeeder\Services\Channels\Marketplace\Ebay\EbayPlatformApp::merge(
                is_array($creds) ? $creds : [],
                $sandbox,
                !empty($channel->settings['custom_ebay_app']),
            );
        } elseif ($type === 'amazon') {
            $creds = \Module\StoreFeeder\Services\Channels\Marketplace\Amazon\AmazonPlatformApp::merge(
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

        foreach ($this->providerRoots($base) as $dirPath) {
            $configFile = $dirPath . '/config.php';
            if (!is_file($configFile)) {
                continue;
            }

            $config = include $configFile;
            if (!is_array($config)) {
                continue;
            }

            $dir = basename($dirPath);
            $key = strtolower((string) ($config['type'] ?? $dir));

            if (isset($out[$key])) {
                throw new RuntimeException(
                    "Duplicate channel type '{$key}' (folder '{$dirPath}')."
                );
            }

            $out[$key] = $config;
        }

        ksort($out, SORT_STRING | SORT_FLAG_CASE);

        return $this->typesCache = $out;
    }

    /**
     * Provider package roots: channels/{marketplace|courier|webstore}/{type}
     * and legacy channels/{type} if any remain.
     *
     * @return list<string>
     */
    private function providerRoots(string $base): array
    {
        $roots = [];

        foreach (self::CATEGORY_DIRS as $category) {
            $categoryPath = $base . '/' . $category;
            if (!is_dir($categoryPath)) {
                continue;
            }
            foreach ($this->childDirectories($categoryPath) as $dirPath) {
                $roots[] = $dirPath;
            }
        }

        // Legacy flat layout (channels/{type}/config.php) for any leftovers.
        foreach ($this->childDirectories($base) as $dirPath) {
            $name = basename($dirPath);
            if (in_array($name, self::CATEGORY_DIRS, true)) {
                continue;
            }
            $roots[] = $dirPath;
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function childDirectories(string $parent): array
    {
        $entries = scandir($parent) ?: [];
        sort($entries, SORT_STRING | SORT_FLAG_CASE);
        $out = [];
        foreach ($entries as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            $dirPath = $parent . '/' . $dir;
            if (is_dir($dirPath)) {
                $out[] = $dirPath;
            }
        }
        return $out;
    }

    /** Absolute path to a provider config.php, or null. */
    public function configPathForType(string $type): ?string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return null;
        }

        $base = __DIR__ . '/channels';
        foreach (self::CATEGORY_DIRS as $category) {
            $file = $base . '/' . $category . '/' . $type . '/config.php';
            if (is_file($file)) {
                return $file;
            }
        }

        $legacy = $base . '/' . $type . '/config.php';
        return is_file($legacy) ? $legacy : null;
    }
}
