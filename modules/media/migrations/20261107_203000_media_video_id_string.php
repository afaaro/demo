<?php
declare(strict_types=1);

use System\Library\Database;

/**
 * Convert media video_id from auto-increment INT to YouTube-style random strings.
 */
return [
    'up' => function (Database $db) {
        $col = $db->query("SHOW COLUMNS FROM `#__media_videos` LIKE 'video_id'")->row;
        $type = strtolower((string) ($col['Type'] ?? ''));
        if (str_contains($type, 'varchar')) {
            return;
        }

        require_once dirname(__DIR__) . '/helpers/functions.php';

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        $db->query('ALTER TABLE `#__media_video_reactions` MODIFY `video_id` VARCHAR(16) NOT NULL');
        $db->query('ALTER TABLE `#__media_video_comments` MODIFY `video_id` VARCHAR(16) NOT NULL');
        $db->query('ALTER TABLE `#__media_videos` MODIFY `video_id` VARCHAR(16) NOT NULL');

        $rows = $db->query('SELECT `video_id` FROM `#__media_videos`')->rows;
        $used = [];
        foreach ($rows as $row) {
            $used[(string) $row['video_id']] = true;
        }

        foreach ($rows as $row) {
            $old = (string) $row['video_id'];
            // Already a public-style id.
            if (media_normalize_video_id($old) !== '' && !ctype_digit($old)) {
                continue;
            }

            $new = media_new_video_id(static function (string $id) use (&$used): bool {
                return isset($used[$id]);
            });
            $used[$new] = true;
            unset($used[$old]);

            $db->query(
                'UPDATE `#__media_videos` SET `video_id` = ? WHERE `video_id` = ?',
                [$new, $old]
            );
            $db->query(
                'UPDATE `#__media_video_reactions` SET `video_id` = ? WHERE `video_id` = ?',
                [$new, $old]
            );
            $db->query(
                'UPDATE `#__media_video_comments` SET `video_id` = ? WHERE `video_id` = ?',
                [$new, $old]
            );
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    },

    'down' => function (Database $db) {
        // Irreversible without losing public ids — leave as VARCHAR.
    },
];
