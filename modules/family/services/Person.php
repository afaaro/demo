<?php
declare(strict_types=1);

namespace Module\Family\Services;

final class Person
{
    public ?int    $id         = null;
    public string  $slug       = '';
    public string  $name       = '';
    public string  $gender     = 'male';
    public ?string $birthDate  = null;
    public ?string $deathDate  = null;
    public ?int    $fatherId   = null;
    public ?int    $motherId   = null;
    public ?int    $marriageId = null;
    public string  $notes      = '';
    public ?string $photo      = null;
    public bool    $active     = true;
    public ?string $createdAt  = null;
    public ?string $updatedAt  = null;

    public ?string $fatherName = null;
    public ?string $motherName = null;

    public function isMale(): bool
    {
        return $this->gender === 'male';
    }

    public function isFemale(): bool
    {
        return $this->gender === 'female';
    }

    public function isDeceased(?string $asOf = null): bool
    {
        if ($this->deathDate === null || trim($this->deathDate) === '') {
            return false;
        }
        $asOf = $asOf ?? date('Y-m-d');
        return $this->deathDate <= $asOf;
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'gender'      => $this->gender,
            'birth_date'  => $this->birthDate,
            'death_date'  => $this->deathDate,
            'father_id'   => $this->fatherId,
            'mother_id'   => $this->motherId,
            'marriage_id' => $this->marriageId,
            'notes'       => $this->notes,
            'photo'       => $this->photo,
            'active'      => $this->active,
            'father_name' => $this->fatherName,
            'mother_name' => $this->motherName,
        ];
    }
}
