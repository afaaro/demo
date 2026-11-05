<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

final class Category
{
    public ?int    $id          = null;
    public ?int    $parentId    = null;
    public string  $slug        = '';
    public string  $name        = '';
    public string  $description = '';
    public int     $sortOrder   = 0;
    public bool    $active      = true;
    public ?string $createdAt   = null;
    public ?string $updatedAt   = null;

    /** @var array<int, Category> */
    public array $children = [];

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'parent_id'   => $this->parentId,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'sort_order'  => $this->sortOrder,
            'active'      => $this->active,
        ];
    }
}