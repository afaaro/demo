<?php
declare(strict_types=1);

namespace Module\Store\Services;

final class AttributeValue
{
    public ?int   $id          = null;
    public ?int   $attributeId = null;
    public string $value       = '';
    public string $label       = '';
    public int    $sortOrder   = 0;

    public function labelOrValue(): string
    {
        return $this->label !== '' ? $this->label : $this->value;
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'attribute_id' => $this->attributeId,
            'value'        => $this->value,
            'label'        => $this->label,
            'sort_order'   => $this->sortOrder,
        ];
    }
}