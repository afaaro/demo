<?php
declare(strict_types=1);

use System\Engine\Registry;
use System\Library\Database;
use System\Library\Request;
use System\Library\Session;

if (!function_exists('knowledge_module_config')) {
    /**
     * @return array{
     *   entries_per_page:int,
     *   locales:list<string>,
     *   default_locale:string,
     *   allow_guest_votes:bool
     * }
     */
    function knowledge_module_config(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $defaults = [
            'entries_per_page'  => 20,
            'locales'           => ['en', 'so', 'ar'],
            'default_locale'    => 'en',
            'allow_guest_votes' => true,
        ];

        try {
            /** @var Database $db */
            $db = Registry::getInstance()->get('db');
            $row = $db->first('modules', ['name' => 'knowledge']);
            if ($row === []) {
                return $cached = $defaults;
            }
            $cfg = json_decode((string) ($row['config'] ?? '{}'), true);
            if (!is_array($cfg)) {
                return $cached = $defaults;
            }

            $locales = knowledge_parse_locales((string) ($cfg['locales'] ?? 'en,so,ar'));
            if ($locales === []) {
                $locales = $defaults['locales'];
            }

            $default = strtolower(trim((string) ($cfg['default_locale'] ?? 'en')));
            if ($default === '' || !in_array($default, $locales, true)) {
                $default = $locales[0];
            }

            $perPage = (int) ($cfg['entries_per_page'] ?? $defaults['entries_per_page']);

            return $cached = [
                'entries_per_page'  => max(5, min(100, $perPage)),
                'locales'           => $locales,
                'default_locale'    => $default,
                'allow_guest_votes' => !empty($cfg['allow_guest_votes']),
            ];
        } catch (\Throwable) {
            return $cached = $defaults;
        }
    }
}

if (!function_exists('knowledge_parse_locales')) {
    /** @return list<string> */
    function knowledge_parse_locales(string $csv): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower(trim($csv))) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^a-z0-9_-]/', '', (string) $part) ?? '';
            if ($part === '' || in_array($part, $out, true)) {
                continue;
            }
            $out[] = $part;
        }
        return $out;
    }
}

if (!function_exists('knowledge_resolve_lang')) {
    /**
     * Resolve content language from query, session, then default.
     * Persists choice in session when a valid `lang` query is present.
     */
    function knowledge_resolve_lang(?Request $request = null, ?Session $session = null): string
    {
        $cfg = knowledge_module_config();
        $locales = $cfg['locales'];
        $default = $cfg['default_locale'];

        try {
            $registry = Registry::getInstance();
            $request ??= $registry->has('request') ? $registry->get('request') : null;
            $session ??= $registry->has('session') ? $registry->get('session') : null;
        } catch (\Throwable) {
            // keep nulls
        }

        $fromQuery = '';
        if ($request !== null) {
            $fromQuery = strtolower(trim((string) $request->get('lang', 'string', '')));
        }

        if ($fromQuery !== '' && in_array($fromQuery, $locales, true)) {
            if ($session !== null) {
                $session->set('knowledge.lang', $fromQuery);
            }
            return $fromQuery;
        }

        if ($session !== null) {
            $fromSession = strtolower(trim((string) $session->get('knowledge.lang', '')));
            if ($fromSession !== '' && in_array($fromSession, $locales, true)) {
                return $fromSession;
            }
        }

        return $default;
    }
}

if (!function_exists('knowledge_locale_label')) {
    function knowledge_locale_label(string $locale): string
    {
        return match (strtolower($locale)) {
            'en' => 'English',
            'so' => 'Somali',
            'ar' => 'Arabic',
            default => strtoupper($locale),
        };
    }
}

if (!function_exists('knowledge_voter_key')) {
    function knowledge_voter_key(?int $userId, Request $request, Session $session): string
    {
        if ($userId !== null && $userId > 0) {
            return 'u:' . $userId;
        }

        $sid = '';
        try {
            $sid = (string) $session->id();
        } catch (\Throwable) {
            $sid = (string) session_id();
        }
        if ($sid === '') {
            $sid = (string) session_id();
        }
        $ip = (string) ($request->ip() ?? '');
        return 'g:' . hash('sha256', $sid . '|' . $ip);
    }
}
