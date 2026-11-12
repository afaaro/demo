<?php
declare(strict_types=1);

use System\Engine\Registry;
use System\Library\Database;

if (!function_exists('family_module_config')) {
    /**
     * @return array{max_wives:int,tree_default_depth:int,people_per_page:int}
     */
    function family_module_config(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $defaults = [
            'max_wives'          => 4,
            'tree_default_depth' => 3,
            'people_per_page'    => 24,
        ];

        try {
            /** @var Database $db */
            $db = Registry::getInstance()->get('db');
            $row = $db->first('modules', ['name' => 'family']);
            if ($row === []) {
                return $cached = $defaults;
            }
            $cfg = json_decode((string) ($row['config'] ?? '{}'), true);
            if (!is_array($cfg)) {
                return $cached = $defaults;
            }

            return $cached = [
                'max_wives'          => max(1, min(4, (int) ($cfg['max_wives'] ?? $defaults['max_wives']))),
                'tree_default_depth' => max(0, min(100, (int) ($cfg['tree_default_depth'] ?? $defaults['tree_default_depth']))),
                'people_per_page'    => max(6, min(100, (int) ($cfg['people_per_page'] ?? $defaults['people_per_page']))),
            ];
        } catch (\Throwable) {
            return $cached = $defaults;
        }
    }
}

if (!function_exists('family_max_wives')) {
    function family_max_wives(): int
    {
        return family_module_config()['max_wives'];
    }
}

if (!function_exists('family_photo_url')) {
    /** Public URL for a stored person photo path, or empty string. */
    function family_photo_url(?string $photo): string
    {
        $photo = $photo !== null ? trim(str_replace('\\', '/', $photo)) : '';
        if ($photo === '' || !str_starts_with($photo, 'uploads/') || str_contains($photo, '..')) {
            return '';
        }
        try {
            /** @var \System\Library\Url $url */
            $url = \System\Engine\Registry::getInstance()->get('url');
            return $url->asset($photo);
        } catch (\Throwable) {
            return '/' . ltrim($photo, '/');
        }
    }
}
