<?php
declare(strict_types=1);

namespace Module\Store\Services;

final class Product
{
    public ?int    $id          = null;
    public string  $sku         = '';
    public string  $name        = '';
    public string  $brand       = '';
    public string  $image = '';
    public string  $description = '';
    public string  $barcode     = '';
    public string  $upc         = '';
    public string  $mpn         = '';
    public string  $supplierSku = '';
    public string  $supplierName = '';
    public string  $warehouse   = 'Default';
    public float   $price       = 0.0;
    public float   $rrp         = 0.0;
    public float   $cost        = 0.0;
    public string  $taxClass    = '20';
    public bool    $priceIncludesVat = false;
    public int     $weightGrams = 0;
    public int     $lengthMm    = 0;
    public int     $widthMm     = 0;
    public int     $heightMm    = 0;
    /** @var array<string, mixed> Shipping & Handling / customs (Store-style). */
    public array   $shipping    = [];
    public string  $notes       = '';
    /** @var array<string, mixed> Regulatory / responsible person / company identifiers. */
    public array   $regulatory  = [];
    /** eBay leaf category id (empty = use channel default). */
    public string  $ebayCategoryId = '';
    public string  $ebayCategoryName = '';
    /** Inventory API condition enum (empty = NEW). */
    public string  $ebayCondition = '';
    /** Show kit parent line on orders (vs components only). */
    public bool    $kitDisplayOnOrder = true;
    public bool    $active      = true;
    public ?string $createdAt   = null;
    public ?string $updatedAt   = null;

    public function toArray(): array
    {
        return [
            'id'                   => $this->id,
            'sku'                  => $this->sku,
            'name'                 => $this->name,
            'brand'                => $this->brand,
            'image'                => $this->image,
            'description'          => $this->description,
            'barcode'              => $this->barcode,
            'upc'                  => $this->upc,
            'mpn'                  => $this->mpn,
            'supplier_sku'         => $this->supplierSku,
            'supplier_name'        => $this->supplierName,
            'warehouse'            => $this->warehouse,
            'price'                => $this->price,
            'rrp'                  => $this->rrp,
            'cost'                 => $this->cost,
            'tax_class'            => $this->taxClass,
            'price_includes_vat'   => $this->priceIncludesVat,
            'weight_grams'         => $this->weightGrams,
            'length_mm'            => $this->lengthMm,
            'width_mm'             => $this->widthMm,
            'height_mm'            => $this->heightMm,
            'shipping'             => $this->shipping,
            'notes'                => $this->notes,
            'regulatory'           => $this->regulatory,
            'ebay_category_id'     => $this->ebayCategoryId,
            'ebay_category_name'   => $this->ebayCategoryName,
            'ebay_condition'       => $this->ebayCondition,
            'kit_display_on_order' => $this->kitDisplayOnOrder,
            'active'               => $this->active,
        ];
    }
}