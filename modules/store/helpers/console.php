<?php
declare(strict_types=1);

use Module\Store\Services\AutomationRepository;
use Module\Store\Services\AutomationRunner;
use Module\Store\Services\ChannelClientFactory;
use Module\Store\Services\ChannelRepository;
use Module\Store\Services\ConversationRepository;
use Module\Store\Services\ConversationSyncService;
use Module\Store\Services\CredentialsVault;
use Module\Store\Services\MessageSyncService;
use Module\Store\Services\OrderSyncService;
use Module\Store\Services\SupportCaseRepository;
use Module\Store\Services\SupportCaseSyncService;
use Module\Store\Services\SyncLogRepository;
use Module\Store\Services\TenantContext;
use System\Engine\Registry;

/**
 * @return array{vault: CredentialsVault, channels: ChannelRepository, factory: ChannelClientFactory}
 */
$bootChannels = static function (Registry $registry): array {
    $registry->singleton('store.vault', fn($r) =>
        CredentialsVault::forTenant(
            (string) $r->get('config')->get('store.credentials_key', '')
        )
    );
    $registry->singleton('store.channels', fn($r) =>
        new ChannelRepository($r->get('db'))
    );
    $registry->singleton('store.factory', fn($r) =>
        new ChannelClientFactory(
            $r->get('store.vault'),
            $r->get('store.channels'),
        )
    );

    return [
        'vault'    => $registry->get('store.vault'),
        'channels' => $registry->get('store.channels'),
        'factory'  => $registry->get('store.factory'),
    ];
};

return [
    'sf:automations:run-due' => function (Registry $registry, array $args) use ($bootChannels): int {
        $boot = $bootChannels($registry);
        $db = $registry->get('db');
        $tenants = $db->query(
            "SELECT `id`, `slug`, `name`, `status` FROM `#__store_tenants` WHERE `status` = 'active'"
        )->rows;

        $exit = 0;
        foreach ($tenants as $tenant) {
            TenantContext::get()->run(
                (int) $tenant['id'],
                (string) $tenant['slug'],
                (string) $tenant['name'],
                (string) $tenant['status'],
                function () use ($db, $boot, $tenant, &$exit): void {
                    $runner = new AutomationRunner(
                        $db,
                        $boot['channels'],
                        $boot['factory'],
                        new AutomationRepository($db),
                        new SyncLogRepository($db),
                    );
                    foreach ($runner->runDue() as $result) {
                        $line = "tenant {$tenant['id']} job {$result['id']}: {$result['status']} {$result['summary']}\n";
                        if ($result['status'] === 'error') {
                            fwrite(STDERR, $line);
                            $exit = 1;
                        } else {
                            echo $line;
                        }
                    }
                }
            );
        }
        return $exit;
    },
    'sf:sync-orders' => function (Registry $registry, array $args) use ($bootChannels): int {
        $bootChannels($registry);
        $registry->singleton('store.sync', fn($r) =>
            new OrderSyncService(
                $r->get('db'),
                $r->get('store.channels'),
                $r->get('store.factory'),
            )
        );

        $days = (int) ($args[0] ?? 30);
        if (!in_array($days, [7, 30, 90], true)) {
            fwrite(STDERR, "Usage: sf:sync-orders [7|30|90]\n");
            return 1;
        }

        $channels = $registry->get('store.channels')->enabled();
        if ($channels === []) {
            echo "No enabled channels.\n";
            return 0;
        }

        $exit = 0;
        foreach ($channels as $channel) {
            if (!$channel->isConnected()) {
                echo "Skipping {$channel->name} (not connected)\n";
                continue;
            }
            echo "Syncing orders {$channel->name} (last {$days}d) ... ";
            try {
                $result = $registry->get('store.sync')->syncChannel($channel, $days);
                if (($result['created'] + $result['updated'] + ($result['skipped'] ?? 0)) === 0
                    && empty($result['truncated'])
                ) {
                    echo "OK — nothing to import (check Import Orders / filters)\n";
                    continue;
                }
                echo "OK — {$result['created']} created, {$result['updated']} updated";
                if (!empty($result['skipped'])) {
                    echo ", {$result['skipped']} skipped";
                }
                if (!empty($result['truncated'])) {
                    echo ' (truncated — run again)';
                }
                echo "\n";
            } catch (\Throwable $e) {
                echo "FAILED: {$e->getMessage()}\n";
                $exit = 1;
            }
        }
        return $exit;
    },

    'sf:sync-conversations' => function (Registry $registry, array $args) use ($bootChannels): int {
        $bootChannels($registry);

        $type = strtoupper(trim((string) ($args[0] ?? 'FROM_MEMBERS')));
        if (!in_array($type, ['FROM_MEMBERS', 'FROM_EBAY', 'BNQ_THREAD', 'AMAZON_ORDER'], true)) {
            fwrite(STDERR, "Usage: sf:sync-conversations [FROM_MEMBERS|FROM_EBAY|BNQ_THREAD|AMAZON_ORDER]\n");
            return 1;
        }

        $channels = $registry->get('store.channels')->enabled();
        if ($channels === []) {
            echo "No enabled channels.\n";
            return 0;
        }

        $factory = $registry->get('store.factory');
        $repo = new ConversationRepository($registry->get('db'));
        $sync = new ConversationSyncService(
            $registry->get('db'),
            $registry->get('store.channels'),
            $factory,
            $repo,
        );

        $exit = 0;
        foreach ($channels as $channel) {
            if (!$channel->isConnected()) {
                echo "Skipping {$channel->name} (not connected)\n";
                continue;
            }
            if ($factory->isCourier($channel)) {
                echo "Skipping {$channel->name} (courier)\n";
                continue;
            }
            $channelType = $type;
            if ($type === 'FROM_MEMBERS') {
                $channelType = match ($channel->type) {
                    'amazon' => 'AMAZON_ORDER',
                    'bnq'    => 'BNQ_THREAD',
                    default  => 'FROM_MEMBERS',
                };
            }
            echo "Syncing conversations {$channel->name} ({$channelType}) ... ";
            try {
                $result = $sync->syncChannel($channel, $channelType);
                echo "OK — {$result['created']} new, {$result['updated']} updated, "
                   . "{$result['messages']} messages, {$result['pages']} page(s)\n";
                if (!empty($result['truncated'])) {
                    echo "  (truncated — run again for more)\n";
                }
            } catch (\Throwable $e) {
                echo "FAILED: {$e->getMessage()}\n";
                $exit = 1;
            }
        }
        return $exit;
    },

    'sf:sync-mail' => function (Registry $registry, array $args) use ($bootChannels): int {
        $bootChannels($registry);

        $folder = (int) ($args[0] ?? 0);
        if (!in_array($folder, [0, 1], true)) {
            fwrite(STDERR, "Usage: sf:sync-mail [0=inbox|1=sent]\n");
            return 1;
        }

        $channels = $registry->get('store.channels')->enabled();
        if ($channels === []) {
            echo "No enabled channels.\n";
            return 0;
        }

        $sync = new MessageSyncService(
            $registry->get('db'),
            $registry->get('store.channels'),
            $registry->get('store.factory'),
        );

        $exit = 0;
        foreach ($channels as $channel) {
            if (!$channel->isConnected()) {
                echo "Skipping {$channel->name} (not connected)\n";
                continue;
            }
            $label = $folder === 1 ? 'sent' : 'inbox';
            echo "Syncing mail {$channel->name} ({$label}) ... ";
            try {
                $result = $sync->syncChannel($channel, $folder, 10);
                echo "OK — {$result['created']} new, {$result['updated']} updated, "
                   . "{$result['pages']} page(s)\n";
                if (!empty($result['truncated'])) {
                    echo "  (truncated — run again for more)\n";
                }
            } catch (\Throwable $e) {
                echo "FAILED: {$e->getMessage()}\n";
                $exit = 1;
            }
        }
        return $exit;
    },

    'sf:sync-cases' => function (Registry $registry, array $args) use ($bootChannels): int {
        $bootChannels($registry);

        $days = (int) ($args[0] ?? 90);
        if (!in_array($days, [7, 30, 90], true)) {
            fwrite(STDERR, "Usage: sf:sync-cases [7|30|90]\n");
            return 1;
        }

        $cases = new SupportCaseRepository($registry->get('db'));
        $sync = new SupportCaseSyncService(
            $registry->get('db'),
            $registry->get('store.factory'),
            $cases,
        );

        $channels = $registry->get('store.channels')->enabled();
        if ($channels === []) {
            echo "No enabled channels.\n";
            return 0;
        }

        $exit = 0;
        $seen = false;
        foreach ($channels as $channel) {
            if (!in_array($channel->type, ['ebay', 'amazon'], true)) {
                continue;
            }
            $seen = true;
            if (!$channel->isConnected()) {
                echo "Skipping {$channel->name} (not connected)\n";
                continue;
            }
            $lookback = $channel->type === 'amazon' ? min(60, $days) : $days;
            echo "Syncing support cases {$channel->name} ({$channel->type}, last {$lookback}d) ... ";
            try {
                $result = $sync->syncChannel($channel, $lookback);
                if ($channel->type === 'amazon') {
                    echo "OK — {$result['created']} created, {$result['updated']} updated, "
                       . ($result['returns'] ?? 0) . " returns, "
                       . ($result['atoz'] ?? 0) . " A-to-Z, "
                       . ($result['safet'] ?? 0) . " SAFE-T";
                } else {
                    echo "OK — {$result['created']} created, {$result['updated']} updated, "
                       . "{$result['cases']} cases, {$result['inquiries']} inquiries, "
                       . "{$result['details']} details";
                }
                if (!empty($result['errors'])) {
                    echo ", {$result['errors']} errors";
                    $exit = 1;
                }
                echo "\n";
            } catch (\Throwable $e) {
                echo "FAILED: {$e->getMessage()}\n";
                $exit = 1;
            }
        }

        if (!$seen) {
            echo "No eBay/Amazon channels configured.\n";
            return 0;
        }

        // Respond-by alerts (overdue + due within 48h).
        $alerts = $cases->respondByAlerts(0, 48, 200);
        if ($alerts === []) {
            echo "No respond-by alerts.\n";
            return $exit;
        }

        $now = time();
        $alertIds = [];
        echo "Respond-by alerts (" . count($alerts) . "):\n";
        foreach ($alerts as $row) {
            $dueRaw = (string) ($row['seller_response_due'] ?? '');
            $dueTs = $dueRaw !== '' ? strtotime($dueRaw) : false;
            $label = ($dueTs !== false && $dueTs < $now) ? 'OVERDUE' : 'DUE_SOON';
            $kind = (string) ($row['kind'] ?? 'case');
            $ext = (string) ($row['external_id'] ?? '');
            $buyer = (string) ($row['buyer_login'] ?? '');
            $status = (string) ($row['status'] ?? '');
            echo "  [{$label}] {$kind} #{$ext} status={$status} buyer={$buyer} due={$dueRaw}\n";
            $alertIds[] = (int) ($row['id'] ?? 0);
        }
        $cases->markAlerted($alertIds);

        return $exit;
    },

    'sf:list-channels' => function (Registry $registry, array $args) use ($bootChannels): int {
        $bootChannels($registry);

        $channels = $registry->get('store.channels')->all();
        if ($channels === []) {
            echo "No channels configured.\n";
            return 0;
        }
        foreach ($channels as $c) {
            printf("%-20s %-8s %-12s %s\n", $c->name, $c->type, $c->status, $c->enabled ? 'enabled' : 'disabled');
        }
        return 0;
    },

    'sf:test-connection' => function (Registry $registry, array $args) use ($bootChannels): int {
        $id = (int) ($args[0] ?? 0);
        if ($id <= 0) {
            fwrite(STDERR, "Usage: sf:test-connection <channel_id>\n");
            return 1;
        }

        $bootChannels($registry);

        $channel = $registry->get('store.channels')->find($id);
        if ($channel === null) {
            fwrite(STDERR, "Channel #{$id} not found.\n");
            return 1;
        }

        $client = $registry->get('store.factory')->make($channel);
        $result = $client->testConnection();
        if (!empty($result['ok'])) {
            echo "OK\n";
            return 0;
        }

        $error = (string) ($result['error'] ?? 'connection failed');
        echo "FAILED: {$error}\n";
        return 1;
    },
];