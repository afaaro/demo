<?php
declare(strict_types=1);

namespace Module\Knowledge\Services;

final class Domain
{
    public ?int    $id        = null;
    public string  $slug      = '';
    public string  $name      = '';
    public int     $sortOrder = 0;
    public bool    $active    = true;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'slug'       => $this->slug,
            'name'       => $this->name,
            'sort_order' => $this->sortOrder,
            'active'     => $this->active,
        ];
    }
}
