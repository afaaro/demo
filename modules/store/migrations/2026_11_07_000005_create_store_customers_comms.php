<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_customers` (
              `id`              int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`       int UNSIGNED NOT NULL,
              `reference`       varchar(64)  NOT NULL,
              `name`            varchar(191) NOT NULL DEFAULT '',
              `email`           varchar(191) NOT NULL DEFAULT '',
              `email_cc`        varchar(512) NOT NULL DEFAULT '',
              `phone`           varchar(64)  NOT NULL DEFAULT '',
              `vat_number`      varchar(64)  NOT NULL DEFAULT '',
              `air_number`      varchar(64)  NOT NULL DEFAULT '',
              `ukims_number`    varchar(64)  NOT NULL DEFAULT '',
              `eori_number`     varchar(64)  NOT NULL DEFAULT '',
              `is_business`     tinyint(1)   NOT NULL DEFAULT 0,
              `notes`           text         NULL,
              `bill_company`    varchar(191) NOT NULL DEFAULT '',
              `bill_address1`   varchar(191) NOT NULL DEFAULT '',
              `bill_address2`   varchar(191) NOT NULL DEFAULT '',
              `bill_city`       varchar(128) NOT NULL DEFAULT '',
              `bill_county`     varchar(128) NOT NULL DEFAULT '',
              `bill_country`    varchar(64)  NOT NULL DEFAULT 'GB',
              `bill_postcode`   varchar(32)  NOT NULL DEFAULT '',
              `ship_company`    varchar(191) NOT NULL DEFAULT '',
              `ship_address1`   varchar(191) NOT NULL DEFAULT '',
              `ship_address2`   varchar(191) NOT NULL DEFAULT '',
              `ship_city`       varchar(128) NOT NULL DEFAULT '',
              `ship_county`     varchar(128) NOT NULL DEFAULT '',
              `ship_country`    varchar(64)  NOT NULL DEFAULT 'GB',
              `ship_postcode`   varchar(32)  NOT NULL DEFAULT '',
              `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`      datetime     NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_tenant_customer_reference` (`tenant_id`, `reference`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_customer_email` (`email`),
              KEY `idx_customer_name` (`name`),
              CONSTRAINT `fk_store_customers_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_messages` (
              `id`             int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`      int UNSIGNED NOT NULL,
              `channel_id`     int UNSIGNED NOT NULL,
              `external_id`    varchar(64)  NOT NULL,
              `folder`         tinyint(1)   NOT NULL DEFAULT 0,
              `sender`         varchar(255) NOT NULL DEFAULT '',
              `recipient`      varchar(255) NOT NULL DEFAULT '',
              `subject`        varchar(500) NOT NULL DEFAULT '',
              `content`        mediumtext   NOT NULL,
              `item_id`        varchar(64)  NOT NULL DEFAULT '',
              `item_title`     varchar(500) NOT NULL DEFAULT '',
              `message_type`   varchar(64)  NOT NULL DEFAULT '',
              `question_type`  varchar(64)  NOT NULL DEFAULT '',
              `is_read`        tinyint(1)   NOT NULL DEFAULT 0,
              `is_replied`     tinyint(1)   NOT NULL DEFAULT 0,
              `receive_date`   datetime     DEFAULT NULL,
              `synced_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_channel_external` (`channel_id`, `external_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_channel_folder` (`channel_id`, `folder`),
              KEY `idx_receive` (`receive_date`),
              KEY `idx_unread` (`channel_id`, `folder`, `is_read`),
              CONSTRAINT `fk_store_messages_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_conversations` (
              `id`                 int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`          int UNSIGNED NOT NULL,
              `channel_id`         int UNSIGNED NOT NULL,
              `external_id`        varchar(64) NOT NULL,
              `conversation_type`  varchar(32) NOT NULL DEFAULT 'FROM_MEMBERS',
              `status`             varchar(32) NOT NULL DEFAULT 'ACTIVE',
              `reference_id`       varchar(64) NOT NULL DEFAULT '',
              `reference_type`     varchar(32) NOT NULL DEFAULT '',
              `unread_count`       int UNSIGNED NOT NULL DEFAULT 0,
              `latest_message_id`  varchar(64) NOT NULL DEFAULT '',
              `latest_body`        varchar(1000) NOT NULL DEFAULT '',
              `latest_sender`      varchar(128) NOT NULL DEFAULT '',
              `latest_date`        datetime DEFAULT NULL,
              `synced_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `created_at`         datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`         datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_channel_external` (`channel_id`, `external_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_channel_status` (`channel_id`, `status`),
              KEY `idx_latest_date` (`latest_date`),
              CONSTRAINT `fk_store_conversations_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_conversation_messages` (
              `id`              int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`       int UNSIGNED NOT NULL,
              `channel_id`      int UNSIGNED NOT NULL,
              `conversation_id` varchar(64)  NOT NULL,
              `external_id`     varchar(64)  NOT NULL,
              `sender`          varchar(128) NOT NULL DEFAULT '',
              `recipient`       varchar(128) NOT NULL DEFAULT '',
              `body`            mediumtext   NOT NULL,
              `read_status`     tinyint(1)   NOT NULL DEFAULT 0,
              `created_date`    datetime     DEFAULT NULL,
              `synced_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_channel_msg` (`channel_id`, `external_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_thread` (`channel_id`, `conversation_id`, `created_date`),
              CONSTRAINT `fk_store_conversation_messages_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS `#__store_support_cases` (
              `id`                  int UNSIGNED NOT NULL AUTO_INCREMENT,
              `tenant_id`           int UNSIGNED NOT NULL,
              `channel_id`          int UNSIGNED NOT NULL,
              `external_id`         varchar(64)  NOT NULL,
              `kind`                varchar(16)  NOT NULL DEFAULT 'case',
              `case_type`           varchar(64)  NOT NULL DEFAULT '',
              `status`              varchar(64)  NOT NULL DEFAULT '',
              `item_id`             varchar(32)  NOT NULL DEFAULT '',
              `transaction_id`      varchar(32)  NOT NULL DEFAULT '',
              `order_id`            varchar(64)  NOT NULL DEFAULT '',
              `buyer_login`         varchar(128) NOT NULL DEFAULT '',
              `claim_amount`        decimal(12,2) DEFAULT NULL,
              `claim_currency`      varchar(8)   NOT NULL DEFAULT '',
              `item_title`          varchar(255) NOT NULL DEFAULT '',
              `seller_response_due` datetime DEFAULT NULL,
              `alerted_at`          datetime DEFAULT NULL,
              `creation_date`       datetime DEFAULT NULL,
              `last_modified_date`  datetime DEFAULT NULL,
              `summary_json`        json NOT NULL DEFAULT (JSON_OBJECT()),
              `detail_json`         json DEFAULT NULL,
              `synced_at`           datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `created_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at`          datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_store_case_channel_ext` (`channel_id`, `kind`, `external_id`),
              KEY `idx_tenant_id` (`tenant_id`),
              KEY `idx_store_case_channel_status` (`channel_id`, `status`),
              KEY `idx_store_case_modified` (`last_modified_date`),
              KEY `idx_store_case_respond_by` (`seller_response_due`),
              KEY `idx_store_case_alerted` (`alerted_at`),
              CONSTRAINT `fk_store_support_cases_tenant`
                FOREIGN KEY (`tenant_id`) REFERENCES `#__store_tenants` (`id`)
                ON DELETE RESTRICT,
              CONSTRAINT `fk_store_case_channel`
                FOREIGN KEY (`channel_id`) REFERENCES `#__store_channels` (`id`)
                ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function (Database $db) {
        $db->query('DROP TABLE IF EXISTS `#__store_support_cases`');
        $db->query('DROP TABLE IF EXISTS `#__store_conversation_messages`');
        $db->query('DROP TABLE IF EXISTS `#__store_conversations`');
        $db->query('DROP TABLE IF EXISTS `#__store_messages`');
        $db->query('DROP TABLE IF EXISTS `#__store_customers`');
    },
];
