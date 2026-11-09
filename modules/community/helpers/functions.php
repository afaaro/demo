<?php
declare(strict_types=1);

use System\Engine\Registry;
use System\Library\Database;

if (!function_exists('community_boot_assets')) {
    function community_boot_assets(\System\Library\View $view, string $title): void
    {
        $view->assign('title', $title);
        $view->setTitle($title);
        $view->addModuleStyle('community', 'community.css');
        $view->addModuleScript('community', 'community.js');
    }
}

if (!function_exists('community_relative_time')) {
    function community_relative_time(mixed $when): string
    {
        if ($when === null || $when === '') {
            return '';
        }
        $ts = is_numeric($when) ? (int) $when : strtotime((string) $when);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'Just now';
        }
        if ($diff < 3600) {
            return max(1, (int) floor($diff / 60)) . 'm';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . 'h';
        }
        if ($diff < 172800) {
            return 'Yesterday';
        }
        return date('j M Y', $ts);
    }
}

if (!function_exists('community_display_name')) {
    function community_display_name(array $row): string
    {
        $name = trim((string) ($row['display_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $username = trim((string) ($row['username'] ?? ''));
        return $username !== '' ? $username : 'User';
    }
}

if (!function_exists('community_asset_url')) {
    function community_asset_url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '' || str_contains($path, '..')) {
            return '';
        }
        if (!str_starts_with($path, 'uploads/')) {
            return '';
        }
        $base = defined('BASEDIR') ? rtrim((string) BASEDIR, '/\\') : '';
        $abs = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (!is_file($abs)) {
            return '';
        }
        return '/' . ltrim(str_replace('\\', '/', $path), '/');
    }
}

if (!function_exists('community_module_config')) {
    /**
     * @return array{posts_per_page:int,max_photo_mb:int,default_visibility:string}
     */
    function community_module_config(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $defaults = [
            'posts_per_page'      => 15,
            'max_photo_mb'        => 5,
            'default_visibility'  => 'friends',
        ];
        try {
            /** @var Database $db */
            $db = Registry::getInstance()->get('db');
            $row = $db->first('modules', ['name' => 'community']);
            if ($row === []) {
                return $cached = $defaults;
            }
            $cfg = json_decode((string) ($row['config'] ?? '{}'), true);
            if (!is_array($cfg)) {
                return $cached = $defaults;
            }
            $perPage = (int) ($cfg['posts_per_page'] ?? $defaults['posts_per_page']);
            $maxMb = (int) ($cfg['max_photo_mb'] ?? $defaults['max_photo_mb']);
            $vis = (string) ($cfg['default_visibility'] ?? $defaults['default_visibility']);
            if (!in_array($vis, ['friends', 'public'], true)) {
                $vis = 'friends';
            }
            return $cached = [
                'posts_per_page'     => max(5, min(50, $perPage)),
                'max_photo_mb'       => max(1, min(20, $maxMb)),
                'default_visibility' => $vis,
            ];
        } catch (\Throwable) {
            return $cached = $defaults;
        }
    }
}

if (!function_exists('community_rate_allow')) {
    /** Simple per-user session throttle for mutating community actions. */
    function community_rate_allow(string $action, int $userId, int $seconds = 3): bool
    {
        if ($userId < 1 || $seconds < 1) {
            return false;
        }
        try {
            $session = Registry::getInstance()->get('session');
            $key = '_community_rl_' . $action . '_' . $userId;
            $last = (int) $session->get($key, 0);
            if ((time() - $last) < $seconds) {
                return false;
            }
            $session->set($key, time());
            return true;
        } catch (\Throwable) {
            return true;
        }
    }
}

if (!function_exists('community_save_photo')) {
    /**
     * Save an uploaded photo under uploads/community/YYYY/MM/DD/.
     *
     * @param array{tmp_name?:string,error?:int,size?:int,name?:string}|null $file
     * @return array{path:string,mime:string}|null
     */
    function community_save_photo(?array $file, int $maxMb = 5): ?array
    {
        if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Photo upload failed.');
        }

        $maxBytes = max(1, $maxMb) * 1024 * 1024;
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Photo must be smaller than ' . $maxMb . ' MB.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (is_string($detected)) {
                    $mime = $detected;
                }
            }
        }
        if ($mime === '') {
            $mime = (string) mime_content_type($tmp);
        }

        $map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        ];
        if (!isset($map[$mime])) {
            throw new RuntimeException('Only JPG, PNG, WEBP, and GIF images are allowed.');
        }

        $imageInfo = @getimagesize($tmp);
        if ($imageInfo === false || empty($imageInfo[0]) || empty($imageInfo[1])) {
            throw new RuntimeException('Invalid image file.');
        }

        $relative = 'uploads/community/' . date('Y/m/d') . '/' . bin2hex(random_bytes(16)) . '.' . $map[$mime];
        $base = defined('BASEDIR') ? rtrim((string) BASEDIR, '/\\') : '';
        $target = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create upload directory.');
        }
        if (!@move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Failed to save photo.');
        }
        @chmod($target, 0644);

        return ['path' => $relative, 'mime' => $mime];
    }
}

if (!function_exists('community_notification_label')) {
    function community_notification_label(string $type): string
    {
        return match ($type) {
            'friend_request'  => 'sent you a friend request',
            'friend_accepted' => 'accepted your friend request',
            'post_like'       => 'liked your post',
            'post_comment'    => 'commented on your post',
            default           => 'sent you a notification',
        };
    }
}
