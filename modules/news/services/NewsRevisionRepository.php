<?php
declare(strict_types=1);

namespace Module\News\Services;

use System\Library\Database;

final class NewsRevisionRepository
{
    private const KEEP = 40;

    public function __construct(private Database $db) {}

    public function enabled(): bool
    {
        return $this->hasTable();
    }

    public function find(int $id): ?NewsRevision
    {
        if (!$this->hasTable() || $id < 1) {
            return null;
        }
        $row = $this->db->query(
            $this->selectSql() . " WHERE r.`id` = ? LIMIT 1",
            [$id]
        )->row;
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, NewsRevision> */
    public function forArticle(int $newsId, int $limit = 20): array
    {
        if (!$this->hasTable() || $newsId < 1) {
            return [];
        }
        $limit = max(1, min(40, $limit));
        $rows = $this->db->query(
            $this->selectSql() . "
              WHERE r.`news_id` = ?
              ORDER BY r.`created_at` DESC, r.`id` DESC
              LIMIT ?",
            [$newsId, $limit]
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    public function snapshot(News $item, ?int $authorId = null): ?NewsRevision
    {
        if (!$this->hasTable() || $item->id === null || $item->id < 1) {
            return null;
        }

        $rev = new NewsRevision();
        $rev->newsId    = (int) $item->id;
        $rev->title     = $item->title;
        $rev->excerpt   = $item->excerpt;
        $rev->body      = $item->body;
        $rev->authorId  = $authorId !== null && $authorId > 0 ? $authorId : $item->authorId;

        $latest = $this->latest((int) $item->id);
        if ($latest !== null && $latest->fingerprint() === $rev->fingerprint()) {
            return $latest;
        }

        $id = $this->db->insert('news_revisions', [
            'news_id'   => $rev->newsId,
            'title'     => mb_substr($rev->title, 0, 255),
            'excerpt'   => mb_substr($rev->excerpt, 0, 500),
            'body'      => $rev->body,
            'author_id' => $rev->authorId,
        ]);
        $this->prune((int) $item->id);

        return $this->find((int) $id);
    }

    public function deleteForArticle(int $newsId): void
    {
        if (!$this->hasTable() || $newsId < 1) {
            return;
        }
        $this->db->query("DELETE FROM `#__news_revisions` WHERE `news_id` = ?", [$newsId]);
    }

    /** @param list<int> $newsIds */
    public function deleteForArticles(array $newsIds): void
    {
        if (!$this->hasTable()) {
            return;
        }
        $newsIds = array_values(array_filter(array_map('intval', $newsIds), static fn($id) => $id > 0));
        if ($newsIds === []) {
            return;
        }
        $in = implode(',', array_fill(0, count($newsIds), '?'));
        $this->db->query("DELETE FROM `#__news_revisions` WHERE `news_id` IN ({$in})", $newsIds);
    }

    private function latest(int $newsId): ?NewsRevision
    {
        $row = $this->db->query(
            $this->selectSql() . "
              WHERE r.`news_id` = ?
              ORDER BY r.`id` DESC
              LIMIT 1",
            [$newsId]
        )->row;
        return $row !== [] ? $this->hydrate($row) : null;
    }

    private function prune(int $newsId): void
    {
        $this->db->query(
            "DELETE FROM `#__news_revisions`
              WHERE `news_id` = ?
                AND `id` NOT IN (
                    SELECT `id` FROM (
                        SELECT `id` FROM `#__news_revisions`
                         WHERE `news_id` = ?
                         ORDER BY `id` DESC
                         LIMIT " . self::KEEP . "
                    ) keep_rows
                )",
            [$newsId, $newsId]
        );
    }

    private function selectSql(): string
    {
        return "SELECT r.*, NULLIF(TRIM(COALESCE(NULLIF(u.`display_name`, ''), u.`username`)), '') AS author_name
                  FROM `#__news_revisions` r
                  LEFT JOIN `users` u ON u.`id` = r.`author_id`";
    }

    private function hasTable(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $ok = $this->db->query("SHOW TABLES LIKE '#__news_revisions'")->rows !== [];
        } catch (\Throwable) {
            $ok = false;
        }
        return $ok;
    }

    private function hydrate(array $row): NewsRevision
    {
        $r = new NewsRevision();
        $r->id         = (int) $row['id'];
        $r->newsId     = (int) $row['news_id'];
        $r->title      = (string) ($row['title'] ?? '');
        $r->excerpt    = (string) ($row['excerpt'] ?? '');
        $r->body       = (string) ($row['body'] ?? '');
        $r->authorId   = isset($row['author_id']) ? (int) $row['author_id'] : null;
        $r->createdAt  = $row['created_at'] ?? null;
        $r->authorName = isset($row['author_name']) && $row['author_name'] !== ''
            ? (string) $row['author_name'] : null;
        return $r;
    }
}
