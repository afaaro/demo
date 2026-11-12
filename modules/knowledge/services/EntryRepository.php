<?php
declare(strict_types=1);

namespace Module\Knowledge\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;
use System\Library\HtmlCleaner;

final class EntryRepository
{
    public function __construct(private Database $db) {}

    public function find(int $id, ?string $locale = null): ?Entry
    {
        $row = $this->db->query(
            $this->entrySelect() . ' WHERE e.`id` = ? LIMIT 1',
            [$id]
        )->row;

        return $row !== [] ? $this->hydrate($row, $locale) : null;
    }

    public function findBySlug(string $slug, ?string $locale = null): ?Entry
    {
        $row = $this->db->query(
            $this->entrySelect() . ' WHERE e.`slug` = ? LIMIT 1',
            [$slug]
        )->row;

        return $row !== [] ? $this->hydrate($row, $locale) : null;
    }

    /**
     * @param array{q?:string,status?:string,domain_id?:int} $filters
     * @return array<int, Entry>
     */
    public function search(
        array $filters,
        int $limit = 100,
        int $offset = 0,
        string $sort = 'created_at',
        string $dir = 'desc',
        ?string $locale = null
    ): array {
        $locale = $locale ?? $this->defaultLocale();
        [$where, $params] = $this->buildSearchWhere($filters, $locale);

        $allowedSorts = [
            'id'          => 'e.`id`',
            'slug'        => 'e.`slug`',
            'status'      => 'e.`status`',
            'created_at'  => 'e.`created_at`',
            'updated_at'  => 'e.`updated_at`',
            'domain_name' => 'd.`name`',
            'title'       => 'COALESCE(tr.`title`, trd.`title`)',
        ];
        $sortSql = $allowedSorts[$sort] ?? $allowedSorts['created_at'];
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';

        $rows = $this->db->query(
            $this->entrySelect($locale) . "
                {$where}
                ORDER BY {$sortSql} {$direction}, e.`id` DESC
                LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;

        return array_map(fn($r) => $this->hydrate($r, $locale), $rows);
    }

    /** @param array{q?:string,status?:string,domain_id?:int} $filters */
    public function countSearch(array $filters, ?string $locale = null): int
    {
        $locale = $locale ?? $this->defaultLocale();
        [$where, $params] = $this->buildSearchWhere($filters, $locale);

        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM `#__knowledge_entries` e
               LEFT JOIN `#__knowledge_domains` d ON d.`id` = e.`domain_id`
               LEFT JOIN `#__knowledge_entry_translations` tr
                 ON tr.`entry_id` = e.`id` AND tr.`locale` = ?
               LEFT JOIN `#__knowledge_entry_translations` trd
                 ON trd.`entry_id` = e.`id` AND trd.`locale` = ?
              {$where}",
            array_merge([$locale, $this->defaultLocale()], $params)
        )->row;

        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array<int, Entry> */
    public function published(
        int $limit,
        int $offset,
        ?int $domainId = null,
        string $q = '',
        ?string $locale = null
    ): array {
        $locale = $locale ?? $this->defaultLocale();
        [$where, $params] = $this->buildPublishedWhere($domainId, $q, $locale);

        $rows = $this->db->query(
            $this->entrySelect($locale) . "
                {$where}
                ORDER BY e.`updated_at` DESC, e.`id` DESC
                LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;

        return array_map(fn($r) => $this->hydrate($r, $locale), $rows);
    }

    public function countPublished(?int $domainId = null, string $q = '', ?string $locale = null): int
    {
        $locale = $locale ?? $this->defaultLocale();
        [$where, $params] = $this->buildPublishedWhere($domainId, $q, $locale);

        $row = $this->db->query(
            "SELECT COUNT(*) AS cnt
               FROM `#__knowledge_entries` e
               LEFT JOIN `#__knowledge_entry_translations` tr
                 ON tr.`entry_id` = e.`id` AND tr.`locale` = ?
               LEFT JOIN `#__knowledge_entry_translations` trd
                 ON trd.`entry_id` = e.`id` AND trd.`locale` = ?
              {$where}",
            array_merge([$locale, $this->defaultLocale()], $params)
        )->row;

        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array{total:int,published:int,draft:int,archived:int} */
    public function countsByStatus(): array
    {
        $rows = $this->db->query(
            "SELECT `status`, COUNT(*) AS cnt FROM `#__knowledge_entries` GROUP BY `status`"
        )->rows;

        $out = ['total' => 0, 'published' => 0, 'draft' => 0, 'archived' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $cnt = (int) ($row['cnt'] ?? 0);
            $out['total'] += $cnt;
            if (isset($out[$status])) {
                $out[$status] = $cnt;
            }
        }
        return $out;
    }

    /** @return array<int, Entry> */
    public function latest(int $limit = 5, ?string $locale = null): array
    {
        return $this->search([], $limit, 0, 'created_at', 'desc', $locale);
    }

    /**
     * @param array{
     *   slug?:string,
     *   domain_id?:int|null,
     *   status?:string,
     *   source_url?:string,
     *   author_id?:int|null,
     *   translations:array<string, array{title?:string,aliases?:string,summary?:string,body?:string}>
     * } $data
     */
    public function create(array $data): Entry
    {
        $translations = $this->normalizeTranslations($data['translations'] ?? []);
        $defaultLocale = $this->defaultLocale();
        $defaultTitle = trim((string) ($translations[$defaultLocale]['title'] ?? ''));

        $status = $this->normalizeStatus((string) ($data['status'] ?? 'draft'));
        if ($status === 'published' && $defaultTitle === '') {
            throw new InvalidArgumentException(
                'Title is required in the default locale (' . $defaultLocale . ') to publish.'
            );
        }

        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slugSeed = $rawSlug !== '' ? $rawSlug : ($defaultTitle !== '' ? $defaultTitle : 'entry');
        $slug = $this->uniqueSlug($this->normalizeSlug($slugSeed));

        $id = $this->db->insert('knowledge_entries', [
            'domain_id'  => isset($data['domain_id']) && (int) $data['domain_id'] > 0
                ? (int) $data['domain_id'] : null,
            'slug'       => $slug,
            'status'     => $status,
            'source_url' => $this->normalizeSourceUrl((string) ($data['source_url'] ?? '')),
            'author_id'  => isset($data['author_id']) && (int) $data['author_id'] > 0
                ? (int) $data['author_id'] : null,
        ]);

        $this->upsertTranslations($id, $translations);

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload entry.');
    }

    public function update(int $id, array $data): Entry
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Entry not found.');
        }

        $translations = isset($data['translations'])
            ? $this->normalizeTranslations($data['translations'])
            : $existing->translations;

        $defaultLocale = $this->defaultLocale();
        $defaultTitle = trim((string) ($translations[$defaultLocale]['title'] ?? $existing->title));

        $update = [];

        if (array_key_exists('domain_id', $data)) {
            $update['domain_id'] = (int) $data['domain_id'] > 0 ? (int) $data['domain_id'] : null;
        }
        if (isset($data['status'])) {
            $status = $this->normalizeStatus((string) $data['status']);
            if ($status === 'published' && $defaultTitle === '') {
                throw new InvalidArgumentException(
                    'Title is required in the default locale (' . $defaultLocale . ') to publish.'
                );
            }
            $update['status'] = $status;
        }
        if (array_key_exists('source_url', $data)) {
            $update['source_url'] = $this->normalizeSourceUrl((string) $data['source_url']);
        }
        if (isset($data['slug'])) {
            $rawSlug = trim((string) $data['slug']);
            $slugSeed = $rawSlug !== '' ? $rawSlug : ($defaultTitle !== '' ? $defaultTitle : $existing->slug);
            $slug = $this->normalizeSlug($slugSeed);
            if ($slug === '') {
                throw new InvalidArgumentException('Slug cannot be empty.');
            }
            $dupe = $this->findBySlug($slug);
            if ($dupe !== null && $dupe->id !== $id) {
                throw new RuntimeException("Slug '{$slug}' already exists.");
            }
            $update['slug'] = $slug;
        }

        if ($update !== []) {
            $this->db->update('knowledge_entries', $update, ['id' => $id]);
        }

        if (isset($data['translations'])) {
            $this->upsertTranslations($id, $translations);
        }

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload entry.');
    }

    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM `#__knowledge_entries` WHERE `id` = ?', [$id]);
    }

    private function entrySelect(?string $locale = null): string
    {
        $locale = $locale ?? $this->defaultLocale();
        $default = $this->defaultLocale();

        // Bound later via build* / find callers that join locale params where needed.
        // For find/search we inject locale literals safely via prepared params in callers.
        return "SELECT e.*,
                       d.`name` AS domain_name,
                       d.`slug` AS domain_slug,
                       COALESCE(tr.`title`, trd.`title`, '') AS title,
                       COALESCE(tr.`aliases`, trd.`aliases`, '') AS aliases,
                       COALESCE(tr.`summary`, trd.`summary`, '') AS summary,
                       COALESCE(tr.`body`, trd.`body`, '') AS body,
                       COALESCE(tr.`locale`, trd.`locale`, " . $this->dbQuote($default) . ") AS resolved_locale
                  FROM `#__knowledge_entries` e
                  LEFT JOIN `#__knowledge_domains` d ON d.`id` = e.`domain_id`
                  LEFT JOIN `#__knowledge_entry_translations` tr
                    ON tr.`entry_id` = e.`id` AND tr.`locale` = " . $this->dbQuote($locale) . "
                  LEFT JOIN `#__knowledge_entry_translations` trd
                    ON trd.`entry_id` = e.`id` AND trd.`locale` = " . $this->dbQuote($default);
    }

    private function dbQuote(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
    }

    /**
     * @param array{q?:string,status?:string,domain_id?:int} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function buildSearchWhere(array $filters, string $locale): array
    {
        $clauses = ['1=1'];
        $params = [];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, ['draft', 'published', 'archived'], true)) {
            $clauses[] = 'e.`status` = ?';
            $params[] = $status;
        }

        $domainId = (int) ($filters['domain_id'] ?? 0);
        if ($domainId > 0) {
            $clauses[] = 'e.`domain_id` = ?';
            $params[] = $domainId;
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $clauses[] = '(e.`slug` LIKE ?
                OR COALESCE(tr.`title`, trd.`title`) LIKE ?
                OR COALESCE(tr.`aliases`, trd.`aliases`) LIKE ?
                OR COALESCE(tr.`summary`, trd.`summary`) LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        // Silence unused — locale used by join in entrySelect.
        unset($locale);

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @return array{0:string,1:list<mixed>} */
    private function buildPublishedWhere(?int $domainId, string $q, string $locale): array
    {
        $clauses = ["e.`status` = 'published'"];
        $params = [];

        if ($domainId !== null && $domainId > 0) {
            $clauses[] = 'e.`domain_id` = ?';
            $params[] = $domainId;
        }

        $q = trim($q);
        if ($q !== '') {
            $like = '%' . $q . '%';
            $clauses[] = '(COALESCE(tr.`title`, trd.`title`) LIKE ?
                OR COALESCE(tr.`aliases`, trd.`aliases`) LIKE ?
                OR COALESCE(tr.`summary`, trd.`summary`) LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        unset($locale);

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /**
     * @param array<string, array{title?:string,aliases?:string,summary?:string,body?:string}> $translations
     * @return array<string, array{title:string,aliases:string,summary:string,body:string}>
     */
    private function normalizeTranslations(array $translations): array
    {
        $locales = $this->configuredLocales();
        $out = [];
        foreach ($locales as $locale) {
            $row = $translations[$locale] ?? [];
            $title = trim((string) ($row['title'] ?? ''));
            $aliases = trim((string) ($row['aliases'] ?? ''));
            $summary = trim((string) ($row['summary'] ?? ''));
            $body = (string) ($row['body'] ?? '');
            if (class_exists(HtmlCleaner::class)) {
                $body = HtmlCleaner::clean($body);
            }
            if ($title === '' && $aliases === '' && $summary === '' && trim(strip_tags($body)) === '') {
                continue;
            }
            $out[$locale] = [
                'title'   => mb_substr($title, 0, 255),
                'aliases' => mb_substr($aliases, 0, 500),
                'summary' => mb_substr($summary, 0, 500),
                'body'    => $body,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array{title:string,aliases:string,summary:string,body:string}> $translations
     */
    private function upsertTranslations(int $entryId, array $translations): void
    {
        $this->db->query(
            'DELETE FROM `#__knowledge_entry_translations` WHERE `entry_id` = ?',
            [$entryId]
        );

        foreach ($translations as $locale => $row) {
            $this->db->insert('knowledge_entry_translations', [
                'entry_id' => $entryId,
                'locale'   => $locale,
                'title'    => $row['title'],
                'aliases'  => $row['aliases'],
                'summary'  => $row['summary'],
                'body'     => $row['body'],
            ]);
        }
    }

    private function hydrate(array $row, ?string $locale = null): Entry
    {
        $e = new Entry();
        $e->id         = (int) $row['id'];
        $e->domainId   = isset($row['domain_id']) && $row['domain_id'] !== null ? (int) $row['domain_id'] : null;
        $e->slug       = (string) $row['slug'];
        $e->status     = (string) ($row['status'] ?? 'draft');
        $e->sourceUrl  = (string) ($row['source_url'] ?? '');
        $e->authorId   = isset($row['author_id']) && $row['author_id'] !== null ? (int) $row['author_id'] : null;
        $e->createdAt  = $row['created_at'] ?? null;
        $e->updatedAt  = $row['updated_at'] ?? null;
        $e->domainName = isset($row['domain_name']) ? (string) $row['domain_name'] : null;
        $e->domainSlug = isset($row['domain_slug']) ? (string) $row['domain_slug'] : null;
        $e->title      = (string) ($row['title'] ?? '');
        $e->aliases    = (string) ($row['aliases'] ?? '');
        $e->summary    = (string) ($row['summary'] ?? '');
        $e->body       = (string) ($row['body'] ?? '');
        $e->locale     = (string) ($row['resolved_locale'] ?? ($locale ?? $this->defaultLocale()));
        $e->translations = $this->loadTranslations((int) $e->id);
        return $e;
    }

    /** @return array<string, array{title:string,aliases:string,summary:string,body:string}> */
    private function loadTranslations(int $entryId): array
    {
        $rows = $this->db->query(
            'SELECT `locale`, `title`, `aliases`, `summary`, `body`
               FROM `#__knowledge_entry_translations`
              WHERE `entry_id` = ?',
            [$entryId]
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['locale']] = [
                'title'   => (string) ($row['title'] ?? ''),
                'aliases' => (string) ($row['aliases'] ?? ''),
                'summary' => (string) ($row['summary'] ?? ''),
                'body'    => (string) ($row['body'] ?? ''),
            ];
        }
        return $out;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    private function normalizeSlug(string $slug): string
    {
        return slug($slug, '-');
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base !== '' ? $base : 'entry';
        $candidate = $slug;
        $i = 2;
        while ($this->findBySlug($candidate) !== null) {
            $candidate = mb_substr($slug, 0, 110) . '-' . $i;
            $i++;
        }
        return $candidate;
    }

    private function normalizeSourceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return mb_substr($url, 0, 500);
    }

    private function defaultLocale(): string
    {
        if (function_exists('knowledge_module_config')) {
            return knowledge_module_config()['default_locale'];
        }
        return 'en';
    }

    /** @return list<string> */
    private function configuredLocales(): array
    {
        if (function_exists('knowledge_module_config')) {
            return knowledge_module_config()['locales'];
        }
        return ['en', 'so', 'ar'];
    }
}
