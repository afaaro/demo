<?php
/**
 * Cross-tenant isolation smoke test (CLI).
 *
 * Usage: php modules/storefeeder/tests/tenant_isolation.php
 */
declare(strict_types=1);

require dirname(__DIR__, 3) . '/maincore.php';

use Module\StoreFeeder\Services\ChannelRepository;
use Module\StoreFeeder\Services\CredentialsVault;
use Module\StoreFeeder\Services\ProductRepository;
use Module\StoreFeeder\Services\TenantContext;
use Module\StoreFeeder\Services\TenantRepository;
use Module\StoreFeeder\Services\TenantTokenRepository;
use System\Services\User;

$db = System\Engine\Registry::getInstance()->get('db');
$tenants = new TenantRepository($db);
$tokens = new TenantTokenRepository($db);
$vault = new CredentialsVault(null);

$a = $tenants->findBySlug('default')
    ?? $tenants->findBySlug('platform')
    ?? $tenants->find(1);
if ($a === null) {
    fwrite(STDERR, "FAIL: platform/default tenant missing\n");
    exit(1);
}

$bId = null;
foreach ($tenants->all() as $t) {
    if ((int) $t['id'] !== (int) $a['id'] && empty($t['settings']['platform_home'])) {
        $bId = (int) $t['id'];
        break;
    }
}
if ($bId === null) {
    $bId = $tenants->create([
        'slug' => 'iso-test-' . bin2hex(random_bytes(3)),
        'name' => 'Isolation Test',
    ]);
}

// Ensure a non-admin user exists for owner assignment tests.
$ownerUser = User::findByLogin('iso-owner@example.com');
if ($ownerUser === null) {
    try {
        $ownerUser = User::create([
            'email'        => 'iso-owner@example.com',
            'username'     => 'iso_owner_' . bin2hex(random_bytes(2)),
            'password'     => 'IsoOwner!' . bin2hex(random_bytes(4)),
            'display_name' => 'Iso Owner',
            'status'       => 'active',
        ]);
    } catch (Throwable $e) {
        // Fallback: any non-admin user
        $row = $db->query(
            "SELECT u.id FROM `#__users` u
              LEFT JOIN `#__user_group_members` m ON m.user_id = u.id
              LEFT JOIN `#__user_groups` g ON g.id = m.group_id AND g.name IN ('admin','sadmin')
             WHERE g.id IS NULL
             ORDER BY u.id ASC LIMIT 1"
        )->row;
        if ($row === []) {
            fwrite(STDERR, 'FAIL: cannot create/find owner user: ' . $e->getMessage() . "\n");
            exit(1);
        }
        $ownerUser = User::find((int) $row['id']);
    }
}
if ($ownerUser === null || $ownerUser->id === null) {
    fwrite(STDERR, "FAIL: owner user missing\n");
    exit(1);
}

$tenants->setOwner($bId, (int) $ownerUser->id);
$ownerRow = $tenants->ownerForTenant($bId);
if ($ownerRow === null || (int) $ownerRow['user_id'] !== (int) $ownerUser->id) {
    fwrite(STDERR, "FAIL: setOwner did not stick\n");
    exit(1);
}

$tokA = $tokens->create((int) $a['id'], 'iso-a');
$tokB = $tokens->create($bId, 'iso-b');

$products = new ProductRepository($db);
$channels = new ChannelRepository($db);

// Seed a product + channel in tenant A (platform home)
TenantContext::get()->set((int) $a['id'], $a['slug'], $a['name'], $a['status'], 'token', ['*']);
$sku = 'ISO-' . bin2hex(random_bytes(4));
try {
    $product = $products->create(['sku' => $sku, 'name' => 'Iso Product', 'active' => true]);
    $productId = (int) $product->id;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL create product A: ' . $e->getMessage() . "\n");
    exit(1);
}

$channelName = 'iso_ch_' . bin2hex(random_bytes(3));
try {
    $channel = $channels->create([
        'name'        => $channelName,
        'type'        => 'ebay',
        'label'       => 'Iso Channel',
        'credentials' => [],
        'settings'    => [],
        'enabled'     => false,
    ], $vault);
    $channelId = (int) $channel->id;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL create channel A: ' . $e->getMessage() . "\n");
    exit(1);
}

// Tenant B must not see product or channel
TenantContext::get()->set($bId, 'b', 'B', 'active', 'membership', ['*']);
$leak = $products->find($productId);
if ($leak !== null) {
    fwrite(STDERR, "FAIL: tenant B can read tenant A product #{$productId}\n");
    exit(1);
}
$bySku = $products->findBySku($sku);
if ($bySku !== null) {
    fwrite(STDERR, "FAIL: tenant B can find tenant A SKU\n");
    exit(1);
}
$leakCh = $channels->find($channelId);
if ($leakCh !== null) {
    fwrite(STDERR, "FAIL: tenant B can read tenant A channel #{$channelId}\n");
    exit(1);
}
$byName = $channels->findByName($channelName);
if ($byName !== null) {
    fwrite(STDERR, "FAIL: tenant B can find tenant A channel name\n");
    exit(1);
}

// Owner memberships: owner of B must not have membership on A unless also assigned
$memberships = $tenants->membershipsForUser((int) $ownerUser->id);
foreach ($memberships as $m) {
    if ((int) $m['tenant_id'] === (int) $a['id']) {
        fwrite(STDERR, "FAIL: tenant B owner unexpectedly member of platform tenant A\n");
        exit(1);
    }
}
$hasB = false;
foreach ($memberships as $m) {
    if ((int) $m['tenant_id'] === $bId && $m['role'] === 'owner') {
        $hasB = true;
    }
}
if (!$hasB) {
    fwrite(STDERR, "FAIL: owner missing membership on tenant B\n");
    exit(1);
}

// Token lookup
$rowA = $tokens->findValidByPlainToken($tokA['token']);
$rowB = $tokens->findValidByPlainToken($tokB['token']);
if ($rowA === null || (int) $rowA['tenant_id'] !== (int) $a['id']) {
    fwrite(STDERR, "FAIL: token A resolve\n");
    exit(1);
}
if ($rowB === null || (int) $rowB['tenant_id'] !== $bId) {
    fwrite(STDERR, "FAIL: token B resolve\n");
    exit(1);
}

$tokens->revoke((int) $a['id'], (int) $tokA['id']);
if ($tokens->findValidByPlainToken($tokA['token']) !== null) {
    fwrite(STDERR, "FAIL: revoked token still valid\n");
    exit(1);
}

echo "OK tenant isolation checks passed (product/channel IDOR + owner + tokens).\n";
exit(0);
