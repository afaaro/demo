<?php
declare(strict_types=1);

namespace Module\News\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class NewsRepository
{
    public function __construct(private Database $db) {}

    // --------------------------------------------------------------
    // Reads
    // --------------------------------------------------------------

    public function find(int $id): ?News
    {
        $row = $this->db->query(
            $this->articleSelect() . " WHERE n.`id` = ? LIMIT 1",
            [$id]
        )->row;

        return $row !== [] ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?News
    {
        $row = $this->db->query(
            $this->articleSelect() . " WHERE n.`slug` = ? LIMIT 1",
            [$slug]
        )->row;

        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, News> */
    public function all(int $limit = 100, int $offset = 0): array
    {
        return $this->search([], $limit, $offset);
    }

    public function countAll(): int
    {
        return $this->countSearch([]);
    }

    /**
     * Admin listing with optional filters.
     *
     * @param array{q?:string,status?:string,category_id?:int} $filters
     * @return array<int, News>
     */
    public function search(array $filters, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildSearchWhere($filters);

        $rows = $this->db->query(
            $this->articleSelect() . "
              {$where}
              ORDER BY n.`created_at` DESC, n.`id` DESC
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @param array{q?:string,status?:string,category_id?:int} $filters */
    public function countSearch(array $filters): int
    {
        [$where, $params] = $this->buildSearchWhere($filters);

        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__news` n {$where}",
            $params
        )->value;
    }

    /**
     * @param array{q?:string,status?:string,category_id?:int} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function buildSearchWhere(array $filters): array
    {
        $clauses = [];
        $params  = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = '(n.`title` LIKE ? OR n.`slug` LIKE ? OR n.`excerpt` LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === 'scheduled') {
            $clauses[] = "((n.`status` = 'published' OR n.`status` = 'draft') AND n.`published_at` IS NOT NULL AND n.`published_at` > ?)";
            $params[]  = date('Y-m-d H:i:s');
        } elseif (in_array($status, ['draft', 'published', 'archived'], true)) {
            $clauses[] = 'n.`status` = ?';
            $params[]  = $status;
        }

        $categoryId = (int) ($filters['category_id'] ?? 0);
        if ($categoryId > 0) {
            $clauses[] = 'n.`category_id` = ?';
            $params[]  = $categoryId;
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }

    /** @return array{total:int,draft:int,published:int,archived:int} */
    public function countsByStatus(): array
    {
        $rows = $this->db->query(
            "SELECT `status`, COUNT(*) AS cnt
               FROM `#__news`
              GROUP BY `status`"
        )->rows;

        $out = ['total' => 0, 'draft' => 0, 'published' => 0, 'archived' => 0];
        foreach ($rows as $r) {
            $status = (string) $r['status'];
            $count  = (int) $r['cnt'];
            if (isset($out[$status])) {
                $out[$status] = $count;
            }
            $out['total'] += $count;
        }
        return $out;
    }

    /** @return array<int, News> */
    public function latest(int $limit = 5): array
    {
        $rows = $this->db->query(
            $this->articleSelect() . "
              ORDER BY n.`created_at` DESC, n.`id` DESC
              LIMIT ?",
            [$limit]
        )->rows;

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, News> */
    public function published(
        int $limit = 10,
        int $offset = 0,
        ?int $categoryId = null,
        string $q = '',
        array $excludeIds = [],
        ?int $tagId = null,
    ): array {
        [$where, $params] = $this->buildPublishedWhere($categoryId, $q, $excludeIds, $tagId);

        $rows = $this->db->query(
            $this->articleSelect() . "
              {$where}
              ORDER BY n.`published_at` DESC, n.`id` DESC
              LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->rows;

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    public function countPublished(?int $categoryId = null, string $q = '', ?int $tagId = null): int
    {
        [$where, $params] = $this->buildPublishedWhere($categoryId, $q, [], $tagId);

        return (int) $this->db->query(
            "SELECT COUNT(*) FROM `#__news` n {$where}",
            $params
        )->value;
    }

    public function featured(): ?News
    {
        if (!$this->hasColumn('featured')) {
            return null;
        }

        $row = $this->db->query(
            $this->articleSelect() . "
              WHERE n.`featured` = 1
                AND n.`status` = 'published'
                AND n.`published_at` IS NOT NULL
                AND n.`published_at` <= ?
              ORDER BY n.`published_at` DESC, n.`id` DESC
              LIMIT 1",
            [date('Y-m-d H:i:s')]
        )->row;

        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, News> */
    public function mostRead(int $limit = 5, array $excludeIds = []): array
    {
        $limit = max(1, min(12, $limit));
        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds), static fn($id) => $id > 0));
        [$where, $params] = $this->buildPublishedWhere(null, '', $excludeIds);
        $order = $this->hasColumn('view_count')
            ? 'n.`view_count` DESC, n.`published_at` DESC, n.`id` DESC'
            : 'n.`published_at` DESC, n.`id` DESC';

        $rows = $this->db->query(
            $this->articleSelect() . "
              {$where}
              ORDER BY {$order}
              LIMIT ?",
            array_merge($params, [$limit])
        )->rows;

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    public function incrementViews(News $item): void
    {
        if ($item->id === null || !$this->hasColumn('view_count')) {
            return;
        }
        $this->db->query(
            "UPDATE `#__news` SET `view_count` = `view_count` + 1 WHERE `id` = ?",
            [(int) $item->id]
        );
        $item->viewCount++;
    }

    public function publishDue(): int
    {
        return $this->db->query(
            "UPDATE `#__news`
                SET `status` = 'published'
              WHERE `status` = 'draft'
                AND `published_at` IS NOT NULL
                AND `published_at` <= ?",
            [date('Y-m-d H:i:s')]
        )->affected;
    }

    /**
     * @param list<int> $excludeIds
     * @return array{0:string,1:list<mixed>}
     */
    private function buildPublishedWhere(?int $categoryId, string $q, array $excludeIds = [], ?int $tagId = null): array
    {
        $clauses = [
            "n.`status` = 'published'",
            'n.`published_at` IS NOT NULL',
            'n.`published_at` <= ?',
        ];
        $params = [date('Y-m-d H:i:s')];

        if ($categoryId !== null && $categoryId > 0) {
            $clauses[] = 'n.`category_id` = ?';
            $params[]  = $categoryId;
        }
        if ($tagId !== null && $tagId > 0) {
            $clauses[] = 'EXISTS (SELECT 1 FROM `#__news_article_tags` p WHERE p.`news_id` = n.`id` AND p.`tag_id` = ?)';
            $params[]  = $tagId;
        }

        $q = trim($q);
        if ($q !== '') {
            $clauses[] = '(n.`title` LIKE ? OR n.`excerpt` LIKE ? OR n.`source` LIKE ?)';
            $like = '%' . $q . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds), static fn($id) => $id > 0));
        if ($excludeIds !== []) {
            $clauses[] = 'n.`id` NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
            array_push($params, ...$excludeIds);
        }

        return ['WHERE ' . implode(' AND ', $clauses), $params];
    }

    /** @return array<int, News> */
    public function related(News $item, int $limit = 4): array
    {
        $limit = max(1, min(12, $limit));
        $params = [date('Y-m-d H:i:s'), (int) $item->id];
        $sql = $this->articleSelect() . "
                 WHERE n.`status` = 'published'
                   AND n.`published_at` IS NOT NULL
                   AND n.`published_at` <= ?
                   AND n.`id` <> ?";

        if ($item->categoryId !== null && $item->categoryId > 0) {
            $sql .= " ORDER BY (n.`category_id` = ?) DESC, n.`published_at` DESC, n.`id` DESC LIMIT ?";
            $params[] = $item->categoryId;
            $params[] = $limit;
        } else {
            $sql .= " ORDER BY n.`published_at` DESC, n.`id` DESC LIMIT ?";
            $params[] = $limit;
        }

        $rows = $this->db->query($sql, $params)->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    // --------------------------------------------------------------
    // Writes
    // --------------------------------------------------------------

    public function create(array $data): News
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Title is required.');
        }

        $rawSlug = trim((string) ($data['slug'] ?? ''));
        $slug    = $this->normalizeSlug($rawSlug !== '' ? $rawSlug : $title);
        if ($slug === '') {
            throw new InvalidArgumentException('Slug is required.');
        }
        if ($this->findBySlug($slug) !== null) {
            throw new RuntimeException("Slug '{$slug}' already exists.");
        }

        $status = $this->normalizeStatus((string) ($data['status'] ?? 'draft'));
        $featured = !empty($data['featured']);
        if ($featured) {
            $this->clearFeatured();
        }

        $row = [
            'category_id'  => isset($data['category_id']) && (int) $data['category_id'] > 0
                ? (int) $data['category_id'] : null,
            'slug'         => $slug,
            'title'        => mb_substr($title, 0, 255),
            'excerpt'      => mb_substr((string) ($data['excerpt'] ?? ''), 0, 500),
            'body'         => (string) ($data['body'] ?? ''),
            'image'        => mb_substr((string) ($data['image'] ?? ''), 0, 500),
            'source'       => mb_substr(trim((string) ($data['source'] ?? '')), 0, 160),
            'source_url'   => $this->normalizeSourceUrl((string) ($data['source_url'] ?? '')),
            'preview_token'=> $this->newPreviewToken(),
            'status'       => $status,
            'published_at' => $this->normalizePublishedAt($data['published_at'] ?? null, $status),
            'author_id'    => isset($data['author_id']) && (int) $data['author_id'] > 0
                ? (int) $data['author_id'] : null,
        ];
        if ($this->hasColumn('featured')) {
            $row['featured'] = $featured ? 1 : 0;
        }
        if ($this->hasColumn('image_caption')) {
            $row['image_caption'] = mb_substr(trim((string) ($data['image_caption'] ?? '')), 0, 300);
        }
        if ($this->hasColumn('image_credit')) {
            $row['image_credit'] = mb_substr(trim((string) ($data['image_credit'] ?? '')), 0, 160);
        }
        if ($this->hasColumn('view_count')) {
            $row['view_count'] = 0;
        }

        $id = $this->db->insert('news', $row);

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload news item.');
    }

    public function update(int $id, array $data): News
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('News item not found.');
        }

        $update = [];

        if (isset($data['title'])) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw new InvalidArgumentException('Title cannot be empty.');
            }
            $update['title'] = mb_substr($title, 0, 255);
        }

        if (array_key_exists('slug', $data)) {
            $rawSlug = trim((string) $data['slug']);
            if ($rawSlug === '') {
                $rawSlug = (string) ($update['title'] ?? $existing->title);
            }
            $slug = $this->normalizeSlug($rawSlug);
            if ($slug === '') {
                throw new InvalidArgumentException('Slug cannot be empty.');
            }
            $dupe = $this->findBySlug($slug);
            if ($dupe !== null && $dupe->id !== $id) {
                throw new RuntimeException("Slug '{$slug}' already exists.");
            }
            $update['slug'] = $slug;
        }

        if (array_key_exists('category_id', $data)) {
            $cid = (int) $data['category_id'];
            $update['category_id'] = $cid > 0 ? $cid : null;
        }
        if (array_key_exists('excerpt', $data)) {
            $update['excerpt'] = mb_substr((string) $data['excerpt'], 0, 500);
        }
        if (array_key_exists('body', $data)) {
            $update['body'] = (string) $data['body'];
        }
        if (array_key_exists('image', $data)) {
            $update['image'] = mb_substr((string) $data['image'], 0, 500);
        }
        if (array_key_exists('image_caption', $data) && $this->hasColumn('image_caption')) {
            $update['image_caption'] = mb_substr(trim((string) $data['image_caption']), 0, 300);
        }
        if (array_key_exists('image_credit', $data) && $this->hasColumn('image_credit')) {
            $update['image_credit'] = mb_substr(trim((string) $data['image_credit']), 0, 160);
        }
        if (array_key_exists('source', $data)) {
            $update['source'] = mb_substr(trim((string) $data['source']), 0, 160);
        }
        if (array_key_exists('source_url', $data)) {
            $update['source_url'] = $this->normalizeSourceUrl((string) $data['source_url']);
        }
        if (array_key_exists('status', $data)) {
            $update['status'] = $this->normalizeStatus((string) $data['status']);
        }

        if (array_key_exists('published_at', $data) || array_key_exists('status', $data)) {
            $status = $update['status'] ?? $existing->status;
            $raw    = array_key_exists('published_at', $data)
                ? $data['published_at']
                : $existing->publishedAt;
            $update['published_at'] = $this->normalizePublishedAt($raw, $status);
        }

        if (array_key_exists('author_id', $data)) {
            $aid = $data['author_id'] !== null ? (int) $data['author_id'] : 0;
            $update['author_id'] = $aid > 0 ? $aid : null;
        }
        if (array_key_exists('featured', $data) && $this->hasColumn('featured')) {
            $featured = !empty($data['featured']);
            if ($featured) {
                $this->clearFeatured($id);
            }
            $update['featured'] = $featured ? 1 : 0;
        }

        if ($update === []) {
            return $existing;
        }

        $this->db->update('news', $update, ['id' => $id]);
        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload news item.');
    }

    public function delete(int $id): void
    {
        $this->db->query("DELETE FROM `#__news` WHERE `id` = ?", [$id]);
    }

    /** @param list<int> $ids */
    public function bulkSetStatus(array $ids, string $status): int
    {
        $ids = $this->normalizeIds($ids);
        $status = $this->normalizeStatus($status);
        if ($ids === []) {
            return 0;
        }
        $in = $this->inClause($ids);
        if ($status === 'published') {
            return $this->db->query(
                "UPDATE `#__news`
                    SET `status` = ?,
                        `published_at` = COALESCE(`published_at`, ?)
                  WHERE `id` IN ({$in})",
                array_merge([$status, date('Y-m-d H:i:s')], $ids)
            )->affected;
        }
        return $this->db->query(
            "UPDATE `#__news` SET `status` = ? WHERE `id` IN ({$in})",
            array_merge([$status], $ids)
        )->affected;
    }

    /** @param list<int> $ids */
    public function bulkSetCategory(array $ids, ?int $categoryId): int
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }
        $cid = $categoryId !== null && $categoryId > 0 ? $categoryId : null;
        return $this->db->query(
            "UPDATE `#__news` SET `category_id` = ? WHERE `id` IN ({$this->inClause($ids)})",
            array_merge([$cid], $ids)
        )->affected;
    }

    /** @param list<int> $ids */
    public function bulkDelete(array $ids): int
    {
        $ids = $this->normalizeIds($ids);
        if ($ids === []) {
            return 0;
        }
        return $this->db->query(
            "DELETE FROM `#__news` WHERE `id` IN ({$this->inClause($ids)})",
            $ids
        )->affected;
    }

    public function ensurePreviewToken(News $item): string
    {
        if (strlen($item->previewToken) >= 32) {
            return $item->previewToken;
        }
        if ($item->id === null) {
            return $this->newPreviewToken();
        }

        $token = $this->newPreviewToken();
        $this->db->update('news', ['preview_token' => $token], ['id' => $item->id]);
        $item->previewToken = $token;
        return $token;
    }

    // --------------------------------------------------------------
    // Internals
    // --------------------------------------------------------------

    private function normalizeSourceUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Source URL must be a valid http or https link.');
        }
        return mb_substr($url, 0, 500);
    }

    private function newPreviewToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function clearFeatured(?int $exceptId = null): void
    {
        if (!$this->hasColumn('featured')) {
            return;
        }
        if ($exceptId !== null && $exceptId > 0) {
            $this->db->query("UPDATE `#__news` SET `featured` = 0 WHERE `featured` = 1 AND `id` <> ?", [$exceptId]);
            return;
        }
        $this->db->query("UPDATE `#__news` SET `featured` = 0 WHERE `featured` = 1");
    }

    private function hasColumn(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        if (!preg_match('/^[a-z_]+$/', $column)) {
            return $cache[$column] = false;
        }
        try {
            $cache[$column] = $this->db->query(
                "SHOW COLUMNS FROM `#__news` LIKE '{$column}'"
            )->rows !== [];
        } catch (\Throwable) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }

    private function articleSelect(): string
    {
        return "SELECT n.*, c.`name` AS category_name, c.`slug` AS category_slug,
                       NULLIF(TRIM(COALESCE(NULLIF(u.`display_name`, ''), u.`username`)), '') AS author_name
                  FROM `#__news` n
                  LEFT JOIN `#__news_categories` c ON c.`id` = n.`category_id`
                  LEFT JOIN `users` u ON u.`id` = n.`author_id`";
    }

    /** @param list<int|string> $ids @return list<int> */
    private function normalizeIds(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        return array_slice(array_values($clean), 0, 100);
    }

    /** @param list<int> $ids */
    private function inClause(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }

    private function normalizeSlug(string $slug): string
    {
        return slug($slug);
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['draft', 'published', 'archived'], true)
            ? $status
            : 'draft';
    }

    private function normalizePublishedAt(mixed $raw, string $status): ?string
    {
        if ($status === 'published') {
            if ($raw === null || $raw === '') {
                return date('Y-m-d H:i:s');
            }
            $ts = strtotime((string) $raw);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        }

        if ($raw === null || $raw === '') {
            return null;
        }
        $ts = strtotime((string) $raw);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function hydrate(array $row): News
    {
        $n = new News();
        $n->id           = (int)    $row['id'];
        $n->categoryId   = isset($row['category_id']) ? (int) $row['category_id'] : null;
        $n->slug         = (string) $row['slug'];
        $n->title        = (string) $row['title'];
        $n->excerpt      = (string) ($row['excerpt'] ?? '');
        $n->body         = (string) ($row['body'] ?? '');
        $n->image        = (string) ($row['image'] ?? '');
        $n->imageCaption = (string) ($row['image_caption'] ?? '');
        $n->imageCredit  = (string) ($row['image_credit'] ?? '');
        $n->source       = (string) ($row['source'] ?? '');
        $n->sourceUrl    = (string) ($row['source_url'] ?? '');
        $n->previewToken = (string) ($row['preview_token'] ?? '');
        $n->status       = (string) ($row['status'] ?? 'draft');
        $n->featured     = !empty($row['featured']);
        $n->viewCount    = (int) ($row['view_count'] ?? 0);
        $n->publishedAt  = $row['published_at'] ?? null;
        $n->authorId     = isset($row['author_id']) ? (int) $row['author_id'] : null;
        $n->authorName   = isset($row['author_name']) && $row['author_name'] !== ''
            ? (string) $row['author_name'] : null;
        $n->createdAt    = $row['created_at'] ?? null;
        $n->updatedAt    = $row['updated_at'] ?? null;
        $n->categoryName = isset($row['category_name']) ? (string) $row['category_name'] : null;
        $n->categorySlug = isset($row['category_slug']) ? (string) $row['category_slug'] : null;
        return $n;
    }
}