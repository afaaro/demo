<?php
declare(strict_types=1);

namespace Module\Store\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class CategoryRepository
{
    use TenantScoped;

    public function __construct(private Database $db) {}

    // =========================================================================
    // Reads
    // =========================================================================

    public function find(int $id): ?Category
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_categories` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1",
            [$id, $this->tenantId()]
        )->row;

        return $row !== [] ? $this->hydrate($row) : null;
    }

    /**
     * Find a category by slug, optionally scoped to a parent.
     * With $parentId = null, looks among root categories.
     */
    public function findBySlug(string $slug, ?int $parentId = null): ?Category
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !TenantContext::get()->has()) {
            return null;
        }
        $tid = $this->tenantId();

        if ($parentId === null) {
            $row = $this->db->query(
                "SELECT * FROM `#__store_categories`
                  WHERE `slug` = ? AND `parent_id` IS NULL AND `tenant_id` = ?
                  LIMIT 1",
                [$slug, $tid]
            )->row;
        } else {
            $row = $this->db->query(
                "SELECT * FROM `#__store_categories`
                  WHERE `slug` = ? AND `parent_id` = ? AND `tenant_id` = ?
                  LIMIT 1",
                [$slug, $parentId, $tid]
            )->row;
        }

        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** Find or create a root category from a slug or display name (e.g. Electronics). */
    public function ensureBySlug(string $slug): Category
    {
        $raw = trim($slug);
        $slug = strtolower($raw);
        if ($slug !== '' && preg_match('/^[a-z0-9-]{2,128}$/', $slug) !== 1) {
            $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $raw), '-'));
        }
        if ($slug === '' || preg_match('/^[a-z0-9-]{2,128}$/', $slug) !== 1) {
            throw new InvalidArgumentException("Cannot make a category from '{$raw}'.");
        }

        $existing = $this->findBySlug($slug);
        if ($existing === null) {
            $existing = $this->findBySlugAnywhere($slug);
        }
        if ($existing !== null) {
            return $existing;
        }

        $name = $raw;
        if ($name === '' || $name === $slug) {
            $name = ucwords(str_replace(['-', '_'], ' ', $slug));
        }

        return $this->create([
            'slug'   => $slug,
            'name'   => $name,
            'active' => true,
        ]);
    }

    public function findBySlugAnywhere(string $slug): ?Category
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !TenantContext::get()->has()) {
            return null;
        }
        $row = $this->db->query(
            "SELECT * FROM `#__store_categories` WHERE `slug` = ? AND `tenant_id` = ? LIMIT 1",
            [$slug, $this->tenantId()]
        )->row;

        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, Category> */
    public function all(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__store_categories`
              WHERE `tenant_id` = ?
              ORDER BY `sort_order`, `name`",
            [$this->tenantId()]
        )->rows;

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /**
     * Root categories with their children loaded recursively.
     *
     * @return array<int, Category>
     */
    public function tree(): array
    {
        if (!TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT * FROM `#__store_categories`
              WHERE `tenant_id` = ?
              ORDER BY `sort_order`, `name`",
            [$this->tenantId()]
        )->rows;

        /** @var array<int, Category> $byId */
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $this->hydrate($row);
        }

        $roots = [];
        foreach ($byId as $cat) {
            if ($cat->parentId === null || !isset($byId[$cat->parentId])) {
                $roots[] = $cat;
            } else {
                $byId[$cat->parentId]->children[] = $cat;
            }
        }
        return $roots;
    }

    /**
     * Flat list of all categories, depth-first, for a <select>.
     * Each row carries its depth so the caller can indent.
     *
     * @return array<int, array{category: Category, depth: int}>
     */
    public function flat(): array
    {
        $tree   = $this->tree();
        $flat   = [];
        $walk   = function (array $nodes, int $depth) use (&$walk, &$flat): void {
            foreach ($nodes as $node) {
                $flat[] = ['category' => $node, 'depth' => $depth];
                if (!empty($node->children)) {
                    $walk($node->children, $depth + 1);
                }
            }
        };
        $walk($tree, 0);
        return $flat;
    }

    /** @return array<int, Category> */
    public function forProduct(int $productId): array
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $rows = $this->db->query(
            "SELECT c.* FROM `#__store_categories` c
               JOIN `#__store_product_categories` pc
                 ON pc.`category_id` = c.`id` AND pc.`tenant_id` = c.`tenant_id`
              WHERE pc.`product_id` = ? AND c.`tenant_id` = ?
              ORDER BY c.`name`",
            [$productId, $tid]
        )->rows;

        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, int> */
    public function categoryIdsFor(int $productId): array
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT `category_id` FROM `#__store_product_categories`
              WHERE `product_id` = ? AND `tenant_id` = ?",
            [$productId, $this->tenantId()]
        )->rows;

        return array_map(fn($r) => (int) $r['category_id'], $rows);
    }

    /**
     * Return the given category and all its descendants' ids.
     * Used to prevent a category from being its own ancestor when reassigning parents.
     *
     * @return array<int, int>
     */
    public function descendantIds(int $categoryId): array
    {
        if ($categoryId <= 0 || !TenantContext::get()->has()) {
            return [];
        }
        $tid = $this->tenantId();
        $ids = [$categoryId];
        $queue = [$categoryId];

        while ($queue !== []) {
            $parentId = array_pop($queue);

            $children = $this->db->query(
                "SELECT `id` FROM `#__store_categories` WHERE `parent_id` = ? AND `tenant_id` = ?",
                [$parentId, $tid]
            )->rows;

            foreach ($children as $row) {
                $childId = (int) $row['id'];
                if (!in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
        }

        return $ids;
    }

    // =========================================================================
    // Writes
    // =========================================================================

    /**
     * @param array{
     *     parent_id?: ?int, slug: string, name: string,
     *     description?: string, sort_order?: int, active?: bool
     * } $data
     */
    public function create(array $data): Category
    {
        $slug = strtolower(trim((string) ($data['slug'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));

        if ($slug === '' || !preg_match('/^[a-z0-9-]{2,128}$/', $slug)) {
            throw new InvalidArgumentException(
                'Slug must be 2–128 chars: lowercase letters, numbers, hyphens.'
            );
        }
        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }

        $parentId = isset($data['parent_id']) && $data['parent_id'] !== null
            ? (int) $data['parent_id'] : null;

        if ($this->findBySlug($slug, $parentId) !== null) {
            throw new RuntimeException("A category with slug '{$slug}' already exists here.");
        }

        $id = $this->db->insert('store_categories', $this->withTenant([
            'parent_id'   => $parentId,
            'slug'        => $slug,
            'name'        => $name,
            'description' => (string) ($data['description'] ?? ''),
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
            'active'      => !empty($data['active']) ? 1 : 0,
        ]));

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload category.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Category
    {
        $allowed = ['parent_id', 'slug', 'name', 'description', 'sort_order', 'active'];
        $data = array_intersect_key($data, array_flip($allowed));

        if ($data === []) {
            throw new InvalidArgumentException('Nothing to update.');
        }

        if (isset($data['slug'])) {
            $data['slug'] = strtolower(trim((string) $data['slug']));
            if (!preg_match('/^[a-z0-9-]{2,128}$/', $data['slug'])) {
                throw new InvalidArgumentException(
                    'Slug must be 2–128 chars: lowercase letters, numbers, hyphens.'
                );
            }
        }

        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
            if ($data['name'] === '') {
                throw new InvalidArgumentException('Name cannot be empty.');
            }
        }

        if (array_key_exists('parent_id', $data)) {
            $data['parent_id'] = $data['parent_id'] === null
                ? null : (int) $data['parent_id'];

            // Prevent cycles: a category cannot be its own ancestor.
            if ($data['parent_id'] !== null) {
                if ($data['parent_id'] === $id) {
                    throw new InvalidArgumentException('A category cannot be its own parent.');
                }
                if (in_array($data['parent_id'], $this->descendantIds($id), true)) {
                    throw new InvalidArgumentException(
                        'A category cannot be moved under one of its own descendants.'
                    );
                }
            }
        }

        if (isset($data['active'])) {
            $data['active'] = !empty($data['active']) ? 1 : 0;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->update('store_categories', $data, $this->withTenant(['id' => $id]));

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload category.');
    }

    /**
     * Delete a category.
     *
     * With ON DELETE SET NULL on parent_id, children become root categories.
     * Product links are removed by the CASCADE on the pivot table.
     */
    public function delete(int $id): void
    {
        if ($id <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $this->db->query(
            "DELETE FROM `#__store_categories` WHERE `id` = ? AND `tenant_id` = ?",
            [$id, $this->tenantId()]
        );
    }

    /**
     * Replace a product's category assignments.
     *
     * @param array<int, int> $categoryIds
     */
    public function setCategoriesFor(int $productId, array $categoryIds): void
    {
        if ($productId <= 0 || !TenantContext::get()->has()) {
            return;
        }
        $tid = $this->tenantId();
        $this->db->transaction(function (Database $db) use ($productId, $categoryIds, $tid) {
            $db->query(
                "DELETE FROM `#__store_product_categories` WHERE `product_id` = ? AND `tenant_id` = ?",
                [$productId, $tid]
            );

            $seen = [];
            foreach ($categoryIds as $id) {
                $id = (int) $id;
                if ($id <= 0 || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $db->query(
                    "INSERT INTO `#__store_product_categories` (`product_id`, `category_id`, `tenant_id`)
                     VALUES (?, ?, ?)",
                    [$productId, $id, $tid]
                );
            }
        });
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Category
    {
        $c = new Category();
        $c->id          = (int)    $row['id'];
        $c->parentId    = isset($row['parent_id']) && $row['parent_id'] !== null
            ? (int) $row['parent_id'] : null;
        $c->slug        = (string) $row['slug'];
        $c->name        = (string) $row['name'];
        $c->description = (string) ($row['description'] ?? '');
        $c->sortOrder   = (int)    ($row['sort_order'] ?? 0);
        $c->active      = (bool)   ($row['active'] ?? true);
        $c->createdAt   = $row['created_at'] ?? null;
        $c->updatedAt   = $row['updated_at'] ?? null;
        return $c;
    }
}