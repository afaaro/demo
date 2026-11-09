<?php
declare(strict_types=1);

use System\Library\Database;

return [
    'up' => function (Database $db) {
        $exists = $db->query(
            'SELECT `id` FROM `#__user_groups` WHERE `name` = ? LIMIT 1',
            ['guest']
        )->row;

        $seedPerms = [
            'news.home.index',
            'news.home.view',
            'forum.home.index',
            'forum.category.view',
            'forum.thread.view',
            'user.home.login',
            'user.register.index',
        ];

        if ($exists === []) {
            $db->insert('user_groups', [
                'name'        => 'guest',
                'label'       => 'Guest',
                'description' => 'Anonymous visitors. Permissions apply when not logged in. Do not assign members.',
                'is_system'   => 1,
                'permissions' => json_encode($seedPerms, JSON_UNESCAPED_UNICODE),
            ]);
        }

        // Point stored user-module config at authenticated when it still says users.
        $mod = $db->query(
            'SELECT `id`, `config` FROM `#__modules` WHERE `name` = ? LIMIT 1',
            ['user']
        )->row;
        if ($mod !== []) {
            $cfg = json_decode((string) ($mod['config'] ?? '{}'), true);
            if (!is_array($cfg)) {
                $cfg = [];
            }
            if (($cfg['default_group'] ?? '') === 'users') {
                $cfg['default_group'] = 'authenticated';
                $db->update('modules', [
                    'config' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
                ], ['id' => (int) $mod['id']]);
            }
        }
    },

    'down' => function (Database $db) {
        $row = $db->query(
            'SELECT `id` FROM `#__user_groups` WHERE `name` = ? LIMIT 1',
            ['guest']
        )->row;
        if ($row !== []) {
            $gid = (int) $row['id'];
            $db->query('DELETE FROM `#__user_group_members` WHERE `group_id` = ?', [$gid]);
            $db->query('DELETE FROM `#__user_groups` WHERE `id` = ?', [$gid]);
        }

        $mod = $db->query(
            'SELECT `id`, `config` FROM `#__modules` WHERE `name` = ? LIMIT 1',
            ['user']
        )->row;
        if ($mod !== []) {
            $cfg = json_decode((string) ($mod['config'] ?? '{}'), true);
            if (is_array($cfg) && ($cfg['default_group'] ?? '') === 'authenticated') {
                $cfg['default_group'] = 'users';
                $db->update('modules', [
                    'config' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
                ], ['id' => (int) $mod['id']]);
            }
        }
    },
];
