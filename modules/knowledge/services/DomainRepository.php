<?php
declare(strict_types=1);

namespace Module\Knowledge\Services;

use InvalidArgumentException;
use RuntimeException;
use System\Library\Database;

final class DomainRepository
{
    public function __construct(private Database $db) {}

    public function find(int $id): ?Domain
    {
        $row = $this->db->first('knowledge_domains', ['id' => $id]);
        return $row !== [] ? $this->hydrate($row) : null;
    }

    public function findBySlug(string $slug): ?Domain
    {
        $row = $this->db->first('knowledge_domains', ['slug' => $slug]);
        return $row !== [] ? $this->hydrate($row) : null;
    }

    /** @return array<int, Domain> */
    public function all(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM `#__knowledge_domains`';
        if ($activeOnly) {
            $sql .= ' WHERE `active` = 1';
        }
        $sql .= ' ORDER BY `sort_order`, `name`';

        $rows = $this->db->query($sql)->rows;
        return array_map(fn($r) => $this->hydrate($r), $rows);
    }

    /** @return array<int, array{id:int,name:string,slug:string,count:int}> */
    public function withCounts(): array
    {
        $rows = $this->db->query(
            "SELECT d.`id`, d.`name`, d.`slug`, COUNT(e.`id`) AS cnt
               FROM `#__knowledge_domains` d
               LEFT JOIN `#__knowledge_entries` e ON e.`domain_id` = d.`id`
              GROUP BY d.`id`, d.`name`, d.`slug`
              ORDER BY d.`sort_order`, d.`name`"
        )->rows;

        return array_map(static fn(array $r): array => [
            'id'    => (int) $r['id'],
            'name'  => (string) $r['name'],
            'slug'  => (string) $r['slug'],
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    public function create(string $slug, string $name, int $sortOrder = 0, bool $active = true): Domain
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Domain name is required.');
        }

        $rawSlug = trim($slug);
        $slug = $this->normalizeSlug($rawSlug !== '' ? $rawSlug : $name);
        if ($slug === '') {
            throw new InvalidArgumentException('Domain slug is required.');
        }
        if ($this->findBySlug($slug) !== null) {
            throw new RuntimeException("Domain '{$slug}' already exists.");
        }

        $id = $this->db->insert('knowledge_domains', [
            'slug'       => $slug,
            'name'       => mb_substr($name, 0, 128),
            'sort_order' => $sortOrder,
            'active'     => $active ? 1 : 0,
        ]);

        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload domain.');
    }

    public function update(int $id, array $data): Domain
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new RuntimeException('Domain not found.');
        }

        $update = [];

        if (isset($data['slug'])) {
            $slug = $this->normalizeSlug((string) $data['slug']);
            if ($slug === '') {
                throw new InvalidArgumentException('Domain slug cannot be empty.');
            }
            $dupe = $this->findBySlug($slug);
            if ($dupe !== null && $dupe->id !== $id) {
                throw new RuntimeException("Domain '{$slug}' already exists.");
            }
            $update['slug'] = $slug;
        }

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Domain name cannot be empty.');
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

        $this->db->update('knowledge_domains', $update, ['id' => $id]);
        return $this->find($id)
            ?? throw new RuntimeException('Failed to reload domain.');
    }

    public function delete(int $id): void
    {
        $this->db->query('DELETE FROM `#__knowledge_domains` WHERE `id` = ?', [$id]);
    }

    private function normalizeSlug(string $slug): string
    {
        return slug($slug, '-');
    }

    private function hydrate(array $row): Domain
    {
        $d = new Domain();
        $d->id        = (int) $row['id'];
        $d->slug      = (string) $row['slug'];
        $d->name      = (string) $row['name'];
        $d->sortOrder = (int) ($row['sort_order'] ?? 0);
        $d->active    = (bool) ($row['active'] ?? true);
        $d->createdAt = $row['created_at'] ?? null;
        $d->updatedAt = $row['updated_at'] ?? null;
        return $d;
    }
}
