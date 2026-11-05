<?php
declare(strict_types=1);

namespace Module\News\Services;

use System\Library\Database;

final class NewsTagRepository
{
    public function __construct(private Database $db) {}

    public function enabled(): bool
    {
        return $this->hasTable('news_tags') && $this->hasTable('news_article_tags');
    }

    public function findBySlug(string $slug): ?NewsTag
    {
        if (!$this->enabled()) {
            return null;
        }
        $row = $this->db->first('news_tags', ['slug' => $slug]);
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, NewsTag> */
    public function all(): array
    {
        if (!$this->enabled()) {
            return [];
        }
        $rows = $this->db->query("SELECT * FROM `#__news_tags` ORDER BY `name`")->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, NewsTag> */
    public function forArticle(int $newsId): array
    {
        if (!$this->enabled() || $newsId < 1) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT t.* FROM `#__news_tags` t
               INNER JOIN `#__news_article_tags` p ON p.`tag_id` = t.`id`
              WHERE p.`news_id` = ?
              ORDER BY t.`name`",
            [$newsId]
        )->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /**
     * @param list<News> $items
     * @return array<int, list<NewsTag>>
     */
    public function forArticles(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item->id !== null && $item->id > 0) {
                $ids[] = (int) $item->id;
            }
        }
        if (!$this->enabled() || $ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->query(
            "SELECT p.`news_id`, t.`id`, t.`slug`, t.`name`
               FROM `#__news_article_tags` p
               INNER JOIN `#__news_tags` t ON t.`id` = p.`tag_id`
              WHERE p.`news_id` IN ({$in})
              ORDER BY t.`name`",
            $ids
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $nid = (int) $row['news_id'];
            $out[$nid] ??= [];
            $out[$nid][] = $this->hydrate($row);
        }
        return $out;
    }

    /** @param list<string> $names */
    public function sync(int $newsId, array $names): void
    {
        if (!$this->enabled() || $newsId < 1) {
            return;
        }

        $ids = [];
        foreach ($names as $name) {
            $tag = $this->findOrCreate((string) $name);
            if ($tag !== null && $tag->id !== null) {
                $ids[(int) $tag->id] = (int) $tag->id;
            }
        }

        $this->db->query("DELETE FROM `#__news_article_tags` WHERE `news_id` = ?", [$newsId]);
        foreach ($ids as $tagId) {
            $this->db->insert('news_article_tags', [
                'news_id' => $newsId,
                'tag_id'  => $tagId,
            ]);
        }
    }

    public function parseNames(string $raw): array
    {
        $parts = preg_split('/[,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $name = trim(preg_replace('/\s+/', ' ', $part) ?? '');
            if ($name === '') {
                continue;
            }
            if (function_exists('mb_substr')) {
                $name = mb_substr($name, 0, 80);
            } else {
                $name = substr($name, 0, 80);
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
            $out[$key] = $name;
        }
        return array_values($out);
    }

    private function findOrCreate(string $name): ?NewsTag
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }
        $slug = slug($name);
        if ($slug === '') {
            return null;
        }

        $row = $this->db->first('news_tags', ['slug' => $slug]);
        if ($row !== []) {
            return $this->hydrate($row);
        }

        $id = $this->db->insert('news_tags', [
            'slug' => $slug,
            'name' => $name,
        ]);
        $tag = new NewsTag();
        $tag->id   = (int) $id;
        $tag->slug = $slug;
        $tag->name = $name;
        return $tag;
    }

    private function hasTable(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        if (!preg_match('/^[a-z_]+$/', $table)) {
            return $cache[$table] = false;
        }
        try {
            $cache[$table] = $this->db->query("SHOW TABLES LIKE '#__{$table}'")->rows !== [];
        } catch (\Throwable) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private function hydrate(array $row): NewsTag
    {
        $t = new NewsTag();
        $t->id   = (int) $row['id'];
        $t->slug = (string) $row['slug'];
        $t->name = (string) $row['name'];
        return $t;
    }
}
