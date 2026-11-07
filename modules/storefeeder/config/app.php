<?php
declare(strict_types=1);

/**
 * StoreFeeder app settings (modules/storefeeder/config/app.php).
 *
 * platform_user_ids — CMS user ids that own the domain (may switch into any
 * tenant). Everyone else is scoped to sf_tenant_users memberships only.
 *
 * credentials_key — master secret for CredentialsVault (HKDF per tenant).
 */
return [
    'platform_user_ids' => [1],
    'credentials_key'   => '',
];
