<?php
declare(strict_types=1);

namespace Module\News\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class NewsCategoryRepository
{
    public function __construct(private Database $db) {}

    public function find(int $id): ?NewsCategory
    {
        $row = $this->db->first('news_categories', ['id' => $id]);
        return $row !== [] ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?NewsCategory
    {
        $row = $this->db->first('news_categories', ['slug' => $slug]);
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, NewsCategory> */
    public function all(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM `#__news_categories`";
        if ($activeOnly) {
            $sql .= " WHERE `active` = 1";
        }
        $sql .= " ORDER BY `sort_order`, `name`";

        $rows = $this->db->query($sql)->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, array{id:int,name:string,slug:string,count:int}> */
    public function withCounts(): array
    {
        $rows = $this->db->query(
            "SELECT c.`id`, c.`name`, c.`slug`, COUNT(n.`id`) AS cnt
               FROM `#__news_categories` c
               LEFT JOIN `#__news` n ON n.`category_id` = c.`id`
              GROUP BY c.`id`, c.`name`, c.`slug`
              ORDER BY c.`sort_order`, c.`name`"
        )->rows;

        return array_map(fn($r) => [
            'id'    => (int) $r['id'],
            'name'  => (string) $r['name'],
            'slug'  => (string) $r['slug'],
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    public function create(string $slug, string $name, int $sortOrder = 0, bool $active = true): NewsCategory
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Category name is required.');
        }

        $rawSlug = trim($slug);
        $slug = $this->normalizeSlug($rawSlug !== '' ? $rawSlug : $name);
        if ($slug === '') {
            throw new InvalidArgumentException('Category slug is required.');
        }
        if ($this->findBySlug($slug) !== null) {
            throw new RuntimeException("Category '{$slug}' already exists.");
        }

        $id = $this->db->insert('news_categories', [
            'slug'       => $slug,
            'name'       => mb_substr($name, 0, 128),
            'sort_order' => $sortOrder,
            'active'     => $active ? 1 : 0,
        ]);

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload category.');
    }

    public function update(int $id, array $data): NewsCategory
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Category not found.');
        }

        $update = [];

        if (isset($data['slug'])) {
            $slug = $this->normalizeSlug((string) $data['slug']);
            if ($slug === '') {
                throw new InvalidArgumentException('Category slug cannot be empty.');
            }
            $dupe = $this->findBySlug($slug);
            if ($dupe !== null && $dupe->id !== $id) {
                throw new RuntimeException("Category '{$slug}' already exists.");
            }
            $update['slug'] = $slug;
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Category name cannot be empty.');
            }
            $update['name'] = mb_substr($name, 0, 128);
        }

        if (array_key_exists('sort_order', $data)) {
            $update['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('active', $data)) {
            $update['active'] = !empty($data['active']) ? 1 : 0;
        }

        if ($update === []) {
            return $existing;
        }

        $this->db->update('news_categories', $update, ['id' => $id]);
        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload category.');
    }

    public function delete(int $id): void
    {
        // FK ON DELETE SET NULL on #__news.category_id detaches articles.
        $this->db->query("DELETE FROM `#__news_categories` WHERE `id` = ?", [$id]);
    }

    private function normalizeSlug(string $slug): string
    {
        return slug($slug, '-');
    }

    private function hydrate(array $row): NewsCategory
    {
        $c = new NewsCategory();
        $c->id        = (int)    $row['id'];
        $c->slug      = (string) $row['slug'];
        $c->name      = (string) $row['name'];
        $c->sortOrder = (int)    ($row['sort_order'] ?? 0);
        $c->active    = (bool)   ($row['active'] ?? true);
        $c->createdAt = $row['created_at'] ?? null;
        $c->updatedAt = $row['updated_at'] ?? null;
        return $c;
    }
}