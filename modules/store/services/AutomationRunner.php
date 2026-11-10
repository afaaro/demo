<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;
use System\Library\Database;

/**
 * Manual / CLI-friendly runner for store automations.
 * External cron can hit store/admin/automations/run-due.
 */
final class AutomationRunner
{
    public function __construct(
        private Database $db,
        private ChannelRepository $channels,
        private ChannelClientFactory $factory,
        private AutomationRepository $automations,
        private SyncLogRepository $syncLog,
    ) {}

    /**
     * @return array{status: string, summary: string}
     */
    public function runOne(int $id): array
    {
        $auto = $this->automations->find($id);
        if ($auto === null) {
            throw new RuntimeException('Automation not found.');
        }
        try {
            $result = $this->dispatch((string) $auto['job_type'], $auto['channel_id'] !== null ? (int) $auto['channel_id'] : 0);
            $this->automations->markRun($id, 'ok');
            return $result;
        } catch (\Throwable $e) {
            $this->automations->markRun($id, 'error');
            if (($auto['channel_id'] ?? null) !== null) {
                $this->syncLog->log(
                    (int) $auto['channel_id'],
                    'automation:' . (string) $auto['job_type'],
                    'error',
                    $e->getMessage()
                );
            }
            throw $e;
        }
    }

    /**
     * Run all enabled automations that are due by interval.
     *
     * @return list<array{id:int, status:string, summary:string}>
     */
    public function runDue(): array
    {
        $out = [];
        foreach ($this->automations->all() as $auto) {
            if (empty($auto['enabled'])) {
                continue;
            }
            $interval = max(5, (int) $auto['interval_minutes']);
            $last = (string) ($auto['last_run_at'] ?? '');
            if ($last !== '') {
                $ts = strtotime($last);
                if ($ts !== false && (time() - $ts) < ($interval * 60)) {
                    continue;
                }
            }
            try {
                $r = $this->runOne((int) $auto['id']);
                $out[] = ['id' => (int) $auto['id'], 'status' => 'ok', 'summary' => $r['summary']];
            } catch (\Throwable $e) {
                $out[] = ['id' => (int) $auto['id'], 'status' => 'error', 'summary' => $e->getMessage()];
            }
        }
        return $out;
    }

    /**
     * @return array{status: string, summary: string}
     */
    private function dispatch(string $jobType, int $channelId): array
    {
        $targets = $this->resolveChannels($channelId);
        if ($targets === []) {
            return ['status' => 'skipped', 'summary' => 'No connected channel.'];
        }

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($targets as $channel) {
            try {
                match ($jobType) {
                    'orders_sync' => (function () use ($channel, &$created, &$updated): void {
                        $r = (new OrderSyncService($this->db, $this->channels, $this->factory))
                            ->syncChannel($channel, 7);
                        $created += (int) ($r['created'] ?? 0);
                        $updated += (int) ($r['updated'] ?? 0);
                    })(),
                    'returns_sync' => (function () use ($channel, &$created, &$updated): void {
                        if (!class_exists(ReturnSyncService::class)) {
                            throw new RuntimeException('ReturnSyncService unavailable.');
                        }
                        $r = (new ReturnSyncService($this->db, $this->channels, $this->factory))
                            ->syncChannel($channel);
                        $created += (int) ($r['created'] ?? $r['imported'] ?? 0);
                        $updated += (int) ($r['updated'] ?? 0);
                    })(),
                    'conversations_sync' => (function () use ($channel, &$created, &$updated): void {
                        $r = (new ConversationSyncService($this->db, $this->channels, $this->factory))
                            ->syncChannel($channel);
                        $created += (int) ($r['created'] ?? $r['imported'] ?? 0);
                        $updated += (int) ($r['updated'] ?? 0);
                    })(),
                    'cases_sync' => (function () use ($channel, &$created, &$updated): void {
                        $r = (new SupportCaseSyncService($this->db, $this->channels, $this->factory))
                            ->syncChannel($channel);
                        $created += (int) ($r['created'] ?? $r['imported'] ?? 0);
                        $updated += (int) ($r['updated'] ?? 0);
                    })(),
                    'tracking_poll' => (function () use ($channel): void {
                        $this->syncLog->log(
                            (int) $channel->id,
                            'automation:tracking_poll',
                            'ok',
                            'Tracking poll triggered (manual/automation).'
                        );
                    })(),
                    default => throw new RuntimeException('Unknown job type.'),
                };
                $this->syncLog->log(
                    (int) $channel->id,
                    'automation:' . $jobType,
                    'ok',
                    "Automation {$jobType} completed."
                );
            } catch (\Throwable $e) {
                $errors[] = $channel->name . ': ' . $e->getMessage();
                $this->syncLog->log(
                    (int) $channel->id,
                    'automation:' . $jobType,
                    'error',
                    $e->getMessage()
                );
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
        return [
            'status'  => 'ok',
            'summary' => "Created {$created}, updated {$updated}.",
        ];
    }

    /** @return list<Channel> */
    private function resolveChannels(int $channelId): array
    {
        if ($channelId > 0) {
            $one = $this->channels->find($channelId);
            return ($one !== null && $one->enabled && $one->isConnected()) ? [$one] : [];
        }
        return array_values(array_filter(
            $this->channels->enabled(),
            static fn(Channel $c): bool => $c->isConnected()
                && !in_array($c->type, CourierShipmentService::COURIER_TYPES, true)
        ));
    }
}
