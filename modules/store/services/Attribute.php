<?php
declare(strict_types=1);

namespace Module\Store\Services;

use Module\Store\Services\AttributeValue;

final class Attribute
{
    public ?int   $id         = null;
    public string $code       = '';
    public string $name       = '';
    public string $type       = 'select';
    public int    $sortOrder  = 0;

    /** @var array<int, AttributeValue> */
    public array $values = [];

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'type'       => $this->type,
            'sort_order' => $this->sortOrder,
            'values'     => array_map(fn(AttributeValue $v) => $v->toArray(), $this->values),
        ];
    }
}