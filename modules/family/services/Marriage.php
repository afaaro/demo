<?php
declare(strict_types=1);

namespace Module\Family\Services;

final class Marriage
{
    public ?int    $id         = null;
    public int     $husbandId  = 0;
    public int     $wifeId     = 0;
    public string  $status     = 'active';
    public ?string $marriedOn  = null;
    public ?string $endedOn    = null;
    public ?string $createdAt  = null;
    public ?string $updatedAt  = null;

    public ?string $husbandName = null;
    public ?string $husbandSlug = null;
    public ?string $wifeName    = null;
    public ?string $wifeSlug    = null;

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'husband_id'   => $this->husbandId,
            'wife_id'      => $this->wifeId,
            'status'       => $this->status,
            'married_on'   => $this->marriedOn,
            'ended_on'     => $this->endedOn,
            'husband_name' => $this->husbandName,
            'husband_slug' => $this->husbandSlug,
            'wife_name'    => $this->wifeName,
            'wife_slug'    => $this->wifeSlug,
        ];
    }
}
