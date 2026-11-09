<?php
declare(strict_types=1);

use System\Library\Database;

$add = static function (Database $db, string $groupName, array $perms): void {
    $row = $db->query(
        'SELECT `id`, `permissions` FROM `#__user_groups` WHERE `name` = ? LIMIT 1',
        [$groupName]
    )->row;
    if ($row === []) {
        return;
    }
    $current = json_decode((string) ($row['permissions'] ?? '[]'), true);
    if (!is_array($current)) {
        $current = [];
    }
    if (in_array('*', $current, true)) {
        return;
    }
    $merged = array_values(array_unique(array_merge($current, $perms)));
    sort($merged);
    $db->update('user_groups', [
        'permissions' => json_encode($merged, JSON_UNESCAPED_UNICODE),
    ], ['id' => (int) $row['id']]);
};

$remove = static function (Database $db, string $groupName, array $perms): void {
    $row = $db->query(
        'SELECT `id`, `permissions` FROM `#__user_groups` WHERE `name` = ? LIMIT 1',
        [$groupName]
    )->row;
    if ($row === []) {
        return;
    }
    $current = json_decode((string) ($row['permissions'] ?? '[]'), true);
    if (!is_array($current)) {
        return;
    }
    if (in_array('*', $current, true)) {
        return;
    }
    $filtered = array_values(array_filter(
        $current,
        static fn($p) => !in_array((string) $p, $perms, true)
    ));
    $db->update('user_groups', [
        'permissions' => json_encode($filtered, JSON_UNESCAPED_UNICODE),
    ], ['id' => (int) $row['id']]);
};

return [
    'up' => function (Database $db) use ($add) {
        $memberPerms = [
            'community.home.index',
            'community.post.create',
            'community.post.delete',
            'community.post.view',
            'community.profile.index',
            'community.friends.index',
            'community.friends.request',
            'community.friends.accept',
            'community.friends.decline',
            'community.friends.unfriend',
            'community.react.toggle',
            'community.comment.post',
            'community.notifications.index',
            'community.notifications.read',
        ];
        $adminPerms = array_merge($memberPerms, ['community.admin']);

        foreach (['authenticated', 'users'] as $groupName) {
            $add($db, $groupName, $memberPerms);
        }
        foreach (['admin', 'moderator'] as $groupName) {
            $add($db, $groupName, $adminPerms);
        }
    },

    'down' => function (Database $db) use ($remove) {
        $all = [
            'community.home.index',
            'community.post.create',
            'community.post.delete',
            'community.post.view',
            'community.profile.index',
            'community.friends.index',
            'community.friends.request',
            'community.friends.accept',
            'community.friends.decline',
            'community.friends.unfriend',
            'community.react.toggle',
            'community.comment.post',
            'community.notifications.index',
            'community.notifications.read',
            'community.admin',
        ];
        foreach (['authenticated', 'users', 'admin', 'moderator'] as $groupName) {
            $remove($db, $groupName, $all);
        }
    },
];
