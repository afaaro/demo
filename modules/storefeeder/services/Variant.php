<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

final class Variant
{
    public ?int    $id        = null;
    public ?int    $productId = null;
    public string  $sku         = '';
    public string  $name        = '';
    /** Units of the 1× SKU in this pack. 0 = auto-detect from name/SKU. */
    public int     $packQty     = 0;
    public string  $pickBin     = '';
    public float   $price       = 0.0;
    public float   $cost        = 0.0;
    public int     $weightGrams = 0;
    /** @var array<string, mixed> */
    public array   $shipping    = [];
    public bool    $active      = true;
    public ?string $createdAt   = null;
    public ?string $updatedAt   = null;

    /**
     * Attribute assignments for this variant.
     * Keyed by attribute id:
     *   [1 => ['attribute_id' => 1, 'value_id' => 3, 'code' => 'size', 'name' => 'Size', 'value' => 'M', 'label' => 'Medium']]
     *
     * @var array<int, array{attribute_id:int, value_id:int, code:string, name:string, value:string, label:string}>
     */
    public array $attributes = [];

    /**
     * Human-readable variant label, e.g. "Red / M".
     */
    public function label(): string
    {
        $parts = [];
        foreach ($this->attributes as $a) {
            $parts[] = $a['label'] !== '' ? $a['label'] : $a['value'];
        }
        return implode(' / ', $parts);
    }

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'product_id'   => $this->productId,
            'sku'          => $this->sku,
            'name'         => $this->name,
            'pack_qty'     => $this->packQty,
            'pick_bin'     => $this->pickBin,
            'price'        => $this->price,
            'cost'         => $this->cost,
            'weight_grams' => $this->weightGrams,
            'shipping'     => $this->shipping,
            'active'       => $this->active,
            'attributes'   => $this->attributes,
            'label'        => $this->label(),
        ];
    }
}