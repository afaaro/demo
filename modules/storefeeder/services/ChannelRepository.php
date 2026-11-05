<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

use InvalidArgumentException;
use Module\StoreFeeder\Services\Channel;
use RuntimeException;
use System\Library\Database;

final class ChannelRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    // --------------------------------------------------------------
    // Reads
    // --------------------------------------------------------------

    public function find(int $id): ?Channel
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->first('sf_channels', $this->withTenant(['id' => $id]));
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** Unscoped lookup (platform tools only). */
    public function findAny(int $id): ?Channel
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->db->first('sf_channels', ['id' => $id]);
        return $row !== [] ? $this->hydrate($row) : null;
    }

    public function findByName(string $name): ?Channel
    {
        if (!TenantContext::get()->has()) {
            return null;
        }
        $where = $this->withTenant(['name' => strtolower(trim($name))]);
        $row = $this->db->first('sf_channels', $where);
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, Channel> */
    public function all(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__sf_channels` WHERE `tenant_id` = ? ORDER BY `label`, `name`",
            [$this->tenantId()]
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** Platform overview — every tenant’s channels. */
    public function allAcrossTenants(): array
    {
        $rows = $this->db->query(
            "SELECT * FROM `#__sf_channels` ORDER BY `tenant_id`, `label`, `name`"
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, Channel> */
    public function enabled(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__sf_channels` WHERE `enabled` = 1 AND `tenant_id` = ? ORDER BY `label`, `name`",
            [$this->tenantId()]
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /**
     * Prefer a connected+enabled eBay channel; fall back to any eBay channel.
     */
    public function findEbayForNotifications(): ?Channel
    {
        if (TenantContext::get()->has()) {
            $row = $this->db->query(
                "SELECT * FROM `#__sf_channels`
                  WHERE `type` = 'ebay' AND `tenant_id` = ?
                  ORDER BY
                    (`status` = 'connected') DESC,
                    (`enabled` = 1) DESC,
                    `id` ASC
                  LIMIT 1",
                [$this->tenantId()]
            )->row;
        } else {
            $row = $this->db->query(
                "SELECT * FROM `#__sf_channels`
                  WHERE `type` = 'ebay'
                  ORDER BY
                    (`status` = 'connected') DESC,
                    (`enabled` = 1) DESC,
                    `id` ASC
                  LIMIT 1"
            )->row;
        }

        return $row !== [] ? $this->hydrate($row) : null;
    }

    /**
     * Public eBay webhooks hit the site host, which is often a different tenant
     * than the merchant who owns the eBay app. Match the notification endpoint
     * across tenants, then any channel that has a verification token.
     */
    public function findEbayForWebhook(string $requestUrl = ''): ?Channel
    {
        $rows = $this->db->query(
            "SELECT * FROM `#__sf_channels`
              WHERE `type` = 'ebay'
              ORDER BY
                (`status` = 'connected') DESC,
                (`enabled` = 1) DESC,
                `id` ASC"
        )->rows;

        if ($rows === []) {
            return null;
        }

        $want = $this->normalizeWebhookUrl($requestUrl);
        $withToken = null;
        foreach ($rows as $row) {
            $ch = $this->hydrate($row);
            $url = $this->normalizeWebhookUrl((string) ($ch->settings['notification_endpoint_url'] ?? ''));
            $token = trim((string) ($ch->settings['notification_verification_token'] ?? ''));
            if ($want !== '' && $url !== '' && $url === $want) {
                return $ch;
            }
            if ($withToken === null && $token !== '') {
                $withToken = $ch;
            }
        }

        return $withToken ?? $this->hydrate($rows[0]);
    }

    private function normalizeWebhookUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return strtolower(rtrim($url, '/'));
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        $path   = rtrim((string) ($parts['path'] ?? ''), '/');
        return $scheme . '://' . $host . $path;
    }

    /**
     * Prefer connected+enabled channel of a type; fall back to any connected of that type.
     */
    public function findConnectedByType(string $type): ?Channel
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return null;
        }

        if (TenantContext::get()->has()) {
            $row = $this->db->query(
                "SELECT * FROM `#__sf_channels`
                  WHERE `type` = ?
                    AND `status` = 'connected'
                    AND `tenant_id` = ?
                  ORDER BY
                    (`enabled` = 1) DESC,
                    `id` ASC
                  LIMIT 1",
                [$type, $this->tenantId()]
            )->row;
        } else {
            $row = $this->db->query(
                "SELECT * FROM `#__sf_channels`
                  WHERE `type` = ?
                    AND `status` = 'connected'
                  ORDER BY
                    (`enabled` = 1) DESC,
                    `id` ASC
                  LIMIT 1",
                [$type]
            )->row;
        }

        return $row !== [] ? $this->hydrate($row) : null;
    }

    // --------------------------------------------------------------
    // Writes
    // --------------------------------------------------------------
    public function create(array $data, CredentialsVault $vault): Channel
    {
        $name = strtolower(trim((string) ($data['name'] ?? '')));
        $type = trim((string) ($data['type'] ?? ''));

        if (!preg_match('/^[a-z0-9_-]{2,64}$/', $name)) {
            throw new InvalidArgumentException(
                'Channel name must be 2–64 chars: a-z, 0-9, dash, underscore.'
            );
        }
        if ($type === '') {
            throw new InvalidArgumentException('Channel type is required.');
        }
        $tenantId = isset($data['tenant_id']) ? (int) $data['tenant_id'] : 0;
        if ($tenantId <= 0) {
            if (!TenantContext::get()->has()) {
                throw new RuntimeException('No tenant context — cannot create channel.');
            }
            $tenantId = $this->tenantId();
        }

        // Name uniqueness is per-tenant.
        $dup = $this->db->first('sf_channels', [
            'name'      => $name,
            'tenant_id' => $tenantId,
        ]);
        if ($dup !== []) {
            throw new RuntimeException("Channel '{$name}' already exists for this tenant.");
        }

        $id = $this->db->insert('sf_channels', [
            'tenant_id'   => $tenantId,
            'name'        => $name,
            'type'        => $type,
            'label'       => mb_substr(
                (string) ($data['label'] ?? ucfirst($name)),
                0,
                128
            ),
            'credentials' => $vault->seal($data['credentials'] ?? []),
            'settings'    => json_encode(
                $data['settings'] ?? [],
                JSON_UNESCAPED_SLASHES
            ),
            'enabled'     => !empty($data['enabled']) ? 1 : 0,
            'status'      => (string) ($data['status'] ?? 'disconnected'),
        ]);

        $created = $this->findAny($id);
        if ($created === null) {
            throw new RuntimeException('Failed to reload channel.');
        }
        return $created;
    }

    public function update(int $id, array $data, CredentialsVault $vault): Channel
    {
        $existing = TenantContext::get()->has()
            ? $this->find($id)
            : $this->findAny($id);
        if ($existing === null && TenantContext::get()->has()) {
            // Platform may be working in another tenant — allow id lookup.
            $existing = $this->findAny($id);
        }
        if ($existing === null) {
            throw new RuntimeException('Channel not found.');
        }

        $update = [];

        if (isset($data['tenant_id'])) {
            $newTid = (int) $data['tenant_id'];
            if ($newTid <= 0) {
                throw new InvalidArgumentException('Invalid tenant.');
            }
            $update['tenant_id'] = $newTid;
        }

        if (isset($data['label'])) {
            $update['label'] = mb_substr(trim((string) $data['label']), 0, 128);
        }

        if (isset($data['type'])) {
            $type = trim((string) $data['type']);
            if ($type === '') {
                throw new InvalidArgumentException('Channel type cannot be empty.');
            }
            $update['type'] = $type;
        }

        if (array_key_exists('settings', $data) && is_array($data['settings'])) {
            $update['settings'] = json_encode(
                $data['settings'],
                JSON_UNESCAPED_SLASHES
            );
        }
        if (array_key_exists('credentials', $data) && is_array($data['credentials'])) {
            $update['credentials'] = $vault->seal($data['credentials']);
        }
        if (isset($data['enabled'])) {
            $update['enabled'] = !empty($data['enabled']) ? 1 : 0;
        }
        if (isset($data['status'])) {
            $update['status'] = (string) $data['status'];
        }
        if (array_key_exists('last_error', $data)) {
            // Column is varchar(500). Truncate defensively.
            $update['last_error'] = mb_substr((string) $data['last_error'], 0, 500);
        }
        if (array_key_exists('last_synced_at', $data)) {
            $update['last_synced_at'] = $data['last_synced_at'];
        }

        if ($update === []) {
            return $existing;
        }

        // Always key by id so tenant_id itself can be changed.
        $this->db->update('sf_channels', $update, ['id' => $id]);
        $reloaded = $this->findAny($id);
        if ($reloaded === null) {
            throw new RuntimeException('Failed to reload channel.');
        }
        return $reloaded;
    }

    public function clearTokens(int $channelId): void
    {
        $where = TenantContext::get()->has()
            ? $this->withTenant(['id' => $channelId])
            : ['id' => $channelId];
        $this->db->update('sf_channels', [
            'refresh_token'      => '',
            'access_token'       => '',
            'oauth_state'        => '',
            'access_expires_at'  => null,
            'refresh_expires_at' => null,
            'status'             => 'disconnected',
        ], $where);
    }

    /**
     * Update the "last synced" timestamp and clear the last error.
     * Does not touch credentials, so no vault is required.
     */
    public function touchLastSynced(int $channelId, ?string $syncedAt = null): void
    {
        $where = TenantContext::get()->has()
            ? $this->withTenant(['id' => $channelId])
            : ['id' => $channelId];
        $this->db->update('sf_channels', [
            'last_synced_at' => $syncedAt ?? date('Y-m-d H:i:s'),
            'last_error'     => '',
        ], $where);
    }

    public function delete(int $id): void
    {
        if (TenantContext::get()->has()) {
            $this->db->query(
                "DELETE FROM `#__sf_channels` WHERE `id` = ? AND `tenant_id` = ?",
                [$id, $this->tenantId()]
            );
            return;
        }
        $this->db->query("DELETE FROM `#__sf_channels` WHERE `id` = ?", [$id]);
    }

    /** @return array<string, mixed> */
    public function credentialsFor(Channel $channel, CredentialsVault $vault): array
    {
        return $vault->open($channel->credentials);
    }

    public function saveOAuthState(int $channelId, string $state): void
    {
        $this->db->update('sf_channels', ['oauth_state' => $state], ['id' => $channelId]);
    }

    public function storeTokens(
        int $channelId,
        string $refreshToken,
        string $accessToken,
        int $accessExpiresIn,
        int $refreshExpiresIn,
        CredentialsVault $vault,
    ): void {
        // eBay (and some other providers) may omit refresh_token_expires_in.
        // A value of 0 means "no expiry information" — store NULL so the
        // client's expiry guard correctly skips the check.
        $refreshExpiresAt = $refreshExpiresIn > 0
            ? date('Y-m-d H:i:s', time() + $refreshExpiresIn)
            : null;

        $where = TenantContext::get()->has()
            ? $this->withTenant(['id' => $channelId])
            : ['id' => $channelId];
        $this->db->update('sf_channels', [
            'refresh_token'      => $vault->seal(['token' => $refreshToken]),
            'access_token'       => $vault->seal(['token' => $accessToken]),
            'access_expires_at'  => date('Y-m-d H:i:s', time() + $accessExpiresIn),
            'refresh_expires_at' => $refreshExpiresAt,
            'status'             => 'connected',
            'last_error'         => '',
        ], $where);
    }

    public function readRefreshToken(Channel $channel, CredentialsVault $vault): ?string
    {
        if ($channel->refreshToken === '') {
            return null;
        }
        $decoded = $vault->open($channel->refreshToken);
        return $decoded['token'] ?? null;
    }

    // --------------------------------------------------------------
    // Internals
    // --------------------------------------------------------------

    private function hydrate(array $row): Channel
    {
        $c = new Channel();
        $c->id           = (int)    $row['id'];
        $c->tenantId     = (int)    ($row['tenant_id'] ?? 0);
        $c->name         = (string) $row['name'];
        $c->type         = (string) $row['type'];
        $c->label        = (string) ($row['label'] ?? '');
        $c->credentials  = (string) ($row['credentials'] ?? '');
        $c->settings     = isset($row['settings'])
            ? (json_decode((string) $row['settings'], true) ?: [])
            : [];
        $c->enabled      = (bool)   ($row['enabled'] ?? false);
        $c->status       = (string) ($row['status'] ?? 'disconnected');
        $c->lastError    = (string) ($row['last_error'] ?? '');
        $c->lastSyncedAt = $row['last_synced_at'] ?? null;
        $c->createdAt    = $row['created_at'] ?? null;
        $c->updatedAt    = $row['updated_at'] ?? null;

        $c->refreshToken     = (string) ($row['refresh_token'] ?? '');
        $c->accessToken      = (string) ($row['access_token'] ?? '');
        $c->oauthState       = (string) ($row['oauth_state'] ?? '');
        $c->accessExpiresAt  = $row['access_expires_at'] ?? null;
        $c->refreshExpiresAt = $row['refresh_expires_at'] ?? null;
        return $c;
    }
}