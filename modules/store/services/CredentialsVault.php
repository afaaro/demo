<?php
declare(strict_types=1);

namespace Module\Store\Services;

use RuntimeException;

/**
 * Encrypts channel credentials with sodium.
 *
 * Prefer CredentialsVault::forTenant() so each tenant gets an HKDF-derived key
 * from store.credentials_key. Legacy enc1 blobs encrypted with the raw
 * master key still open via fallback.
 *
 * Generate a master key:
 *     php -r "echo base64_encode(random_bytes(32));"
 */
final class CredentialsVault
{
    private const PLAIN_PREFIX = 'plain:';
    private const ENC_PREFIX   = 'enc1:';
    private const ENC2_PREFIX  = 'enc2:';

    private ?string $key = null;
    private ?string $legacyMaster = null;
    private int $tenantId = 0;

    public function __construct(?string $base64Key)
    {
        if ($base64Key !== null && $base64Key !== '') {
            $decoded = base64_decode($base64Key, true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException(
                    'Invalid credentials key: must be base64 of 32 bytes.'
                );
            }
            $this->key = $decoded;
        }
    }

    /**
     * Build a vault for the current (or given) tenant using HKDF(master, tenant_id).
     */
    public static function forTenant(?string $masterBase64, ?int $tenantId = null): self
    {
        $tid = $tenantId ?? (TenantContext::get()->id() ?? 1);
        $vault = new self(null);
        $vault->tenantId = max(1, $tid);

        if ($masterBase64 === null || $masterBase64 === '') {
            return $vault;
        }

        $master = base64_decode($masterBase64, true);
        if ($master === false || strlen($master) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'Invalid credentials key: must be base64 of 32 bytes.'
            );
        }

        $vault->legacyMaster = $master;
        $vault->key = self::deriveTenantKey($master, $vault->tenantId, 'store-tenant-');
        return $vault;
    }

    /**
     * HKDF tenant key. Info prefix changed during storefeeder → store rename.
     */
    private static function deriveTenantKey(string $master, int $tenantId, string $infoPrefix): string
    {
        return hash_hkdf(
            'sha256',
            $master,
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $infoPrefix . max(1, $tenantId),
            ''
        );
    }

    public function isSecure(): bool
    {
        return $this->key !== null;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    /** Tenant id embedded in an enc2 blob, or 0. */
    public static function peekEnc2Tenant(?string $blob): int
    {
        if ($blob === null || !str_starts_with($blob, self::ENC2_PREFIX)) {
            return 0;
        }
        $rest = substr($blob, strlen(self::ENC2_PREFIX));
        $colon = strpos($rest, ':');
        if ($colon === false) {
            return 0;
        }
        return max(0, (int) substr($rest, 0, $colon));
    }

    public function seal(array $credentials): string
    {
        $json = json_encode($credentials, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Cannot encode credentials.');
        }

        if ($this->key === null) {
            return self::PLAIN_PREFIX . base64_encode($json);
        }

        $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($json, $nonce, $this->key);

        if ($this->tenantId > 0) {
            return self::ENC2_PREFIX . $this->tenantId . ':' . base64_encode($nonce . $ciphertext);
        }

        return self::ENC_PREFIX . base64_encode($nonce . $ciphertext);
    }

    public function open(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }

        if (str_starts_with($blob, self::PLAIN_PREFIX)) {
            $raw = base64_decode(substr($blob, strlen(self::PLAIN_PREFIX)), true);
            if ($raw === false) {
                return [];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (str_starts_with($blob, self::ENC2_PREFIX)) {
            $rest = substr($blob, strlen(self::ENC2_PREFIX));
            $colon = strpos($rest, ':');
            if ($colon === false) {
                throw new RuntimeException('Corrupt encrypted credentials.');
            }
            $blobTenant = (int) substr($rest, 0, $colon);
            if ($this->tenantId > 0 && $blobTenant !== $this->tenantId) {
                throw new RuntimeException('Credentials belong to another tenant.');
            }
            $payloadB64 = substr($rest, $colon + 1);
            return $this->decryptWithFallbacks($payloadB64, max(1, $blobTenant));
        }

        if (str_starts_with($blob, self::ENC_PREFIX)) {
            $payloadB64 = substr($blob, strlen(self::ENC_PREFIX));
            return $this->decryptWithFallbacks($payloadB64, $this->tenantId > 0 ? $this->tenantId : 1);
        }

        $decoded = json_decode($blob, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Try current tenant key, then legacy storefeeder HKDF label, then raw master.
     * Also tries the pre-rename default master pepper when the configured key fails.
     */
    private function decryptWithFallbacks(string $payloadB64, int $tenantId): array
    {
        $keys = [];
        if ($this->key !== null) {
            $keys[] = $this->key;
        }
        if ($this->legacyMaster !== null) {
            $keys[] = self::deriveTenantKey($this->legacyMaster, $tenantId, 'storefeeder-tenant-');
            $keys[] = self::deriveTenantKey($this->legacyMaster, $tenantId, 'store-tenant-');
            $keys[] = $this->legacyMaster;
        }

        // Default peppers used during the module rename — covers hosts that
        // swapped app.php credentials_key without re-sealing channels.
        foreach (['storefeeder-credentials', 'store-credentials'] as $pepper) {
            $alt = hash('sha256', $pepper, true);
            $keys[] = self::deriveTenantKey($alt, $tenantId, 'storefeeder-tenant-');
            $keys[] = self::deriveTenantKey($alt, $tenantId, 'store-tenant-');
            $keys[] = $alt;
        }

        $seen = [];
        $last = null;
        foreach ($keys as $key) {
            $sig = $key;
            if (isset($seen[$sig])) {
                continue;
            }
            $seen[$sig] = true;
            try {
                return $this->decryptPayload($payloadB64, $key);
            } catch (RuntimeException $e) {
                $last = $e;
            }
        }

        if ($last !== null) {
            throw $last;
        }

        throw new RuntimeException('Encrypted credentials found, but no key is configured.');
    }

    private function decryptPayload(string $payloadB64, ?string $key): array
    {
        if ($key === null) {
            throw new RuntimeException(
                'Encrypted credentials found, but no key is configured.'
            );
        }

        $payload = base64_decode($payloadB64, true);
        if ($payload === false) {
            throw new RuntimeException('Corrupt encrypted credentials.');
        }

        $nonceLen = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (strlen($payload) < $nonceLen) {
            throw new RuntimeException('Corrupt encrypted credentials (short).');
        }

        $nonce      = substr($payload, 0, $nonceLen);
        $ciphertext = substr($payload, $nonceLen);
        $plain      = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        if ($plain === false) {
            throw new RuntimeException('Failed to decrypt credentials.');
        }

        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }
}
