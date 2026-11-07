<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_tenants` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `slug`       varchar(64)  NOT NULL,
              `name`       varchar(128) NOT NULL,
              `status`     enum('active','suspended') NOT NULL DEFAULT 'active',
              `settings`   json NOT NULL DEFAULT (JSON_OBJECT()),
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_store_tenant_slug` (`slug`),
              KEY `idx_store_tenant_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_tenant_users` (
              `id`         int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`  int UNSIGNED NOT NULL,
              `user_id`    int UNSIGNED NOT NULL,
              `role`       enum('owner','admin','staff') NOT NULL DEFAULT 'staff',
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_store_tenant_user` (`tenant_id`, `user_id`),
              KEY `idx_store_tenant_users_user` (`user_id`),
              CONSTRAINT `fk_store_tenant_users_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_tenant_tokens` (
              `id`           int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`    int UNSIGNED NOT NULL,
              `name`         varchar(128) NOT NULL DEFAULT '',
              `token_hash`   char(64) NOT NULL,
              `prefix`       varchar(16) NOT NULL DEFAULT '',
              `last4`        char(4) NOT NULL DEFAULT '',
              `scopes_json`  json NOT NULL DEFAULT (JSON_ARRAY()),
              `expires_at`   datetime NULL DEFAULT NULL,
              `last_used_at` datetime NULL DEFAULT NULL,
              `revoked_at`   datetime NULL DEFAULT NULL,
              `created_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_store_tenant_token_hash` (`token_hash`),
              KEY `idx_store_tenant_tokens_tenant` (`tenant_id`),
              CONSTRAINT `fk_store_tenant_tokens_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $exists = (int) $db->query(
            "SELECT COUNT(*) FROM `#__store_tenants` WHERE `id` = 1"
        )->value;
        if ($exists === 0) {
            $db->insert('store_tenants', [
                'id'         => 1,
                'slug'       => 'default',
                'name'       => 'Default',
                'status'     => 'active',
                'settings'   => '{"platform_home":true}',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        try {
            $db->query(
                "INSERT IGNORE INTO `#__store_tenant_users` (`tenant_id`, `user_id`, `role`, `created_at`)
                 SELECT 1, u.`id`, 'owner', NOW()
                   FROM `#__users` u
                   JOIN `#__user_group_members` m ON m.`user_id` = u.`id`
                   JOIN `#__user_groups` g ON g.`id` = m.`group_id`
                  WHERE g.`name` IN ('admin', 'sadmin', 'moderator')"
            );
        } catch (\Throwable) {
            // groups tables may differ — ignore
        }
    },

    'down' => function (Database $db) {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $db->query('DROP TABLE IF EXISTS `#__store_tenant_tokens`');
            $db->query('DROP TABLE IF EXISTS `#__store_tenant_users`');
            $db->query('DROP TABLE IF EXISTS `#__store_tenants`');
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    },
];
