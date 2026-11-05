<?php
declare(strict_types=1);

use System\Library\Database;

$canonicalEmail = static function (string $email): string {
    $email = strtolower(trim($email));
    if (!str_contains($email, '@')) {
        return $email;
    }
    [$local, $domain] = explode('@', $email, 2);
    if ($domain === 'googlemail.com') {
        $domain = 'gmail.com';
    }
    if (str_contains($local, '+')) {
        $local = explode('+', $local, 2)[0];
    }
    if ($domain === 'gmail.com') {
        $local = str_replace('.', '', $local);
    }
    return $local . '@' . $domain;
};

/**
 * Canonical email for Gmail alias / plus-tag uniqueness, plus purge obvious bot pending accounts.
 */
return [
    'up' => function (Database $db) use ($canonicalEmail) {
        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'email_canonical'")->rows;
        if ($cols === []) {
            $db->query("
                ALTER TABLE `#__users`
                  ADD COLUMN `email_canonical` varchar(255) NOT NULL DEFAULT '' AFTER `email`,
                  ADD KEY `idx_users_email_canonical` (`email_canonical`)
            ");
        }

        $rows = $db->query("SELECT `id`, `email` FROM `#__users`")->rows;
        foreach ($rows as $row) {
            $db->query(
                "UPDATE `#__users` SET `email_canonical` = ? WHERE `id` = ?",
                [$canonicalEmail((string) $row['email']), (int) $row['id']]
            );
        }

        // Remove pending accounts that match common bot fingerprints from the last 30 days.
        $db->query("
            DELETE FROM `#__users`
             WHERE `status` = 'pending'
               AND `created_at` >= (NOW() - INTERVAL 30 DAY)
               AND (
                    `username` REGEXP '^[a-z]{14,}$'
                 OR (`display_name` REGEXP '^[A-Za-z]{10,}$'
                     AND `display_name` REGEXP '[A-Z].*[A-Z].*[A-Z].*[A-Z]')
                 OR (`email` LIKE '%@gmail.com'
                     AND (LENGTH(`email`) - LENGTH(REPLACE(SUBSTRING_INDEX(`email`, '@', 1), '.', ''))) >= 3)
               )
        ");
    },

    'down' => function (Database $db) {
        $cols = $db->query("SHOW COLUMNS FROM `#__users` LIKE 'email_canonical'")->rows;
        if ($cols !== []) {
            $db->query("
                ALTER TABLE `#__users`
                  DROP KEY `idx_users_email_canonical`,
                  DROP COLUMN `email_canonical`
            ");
        }
    },
];
