<?php
declare(strict_types=1);

namespace Module\StoreFeeder\Services;

final class ListingTemplate
{
    public ?int    $id                  = null;
    public int     $channelId           = 0;
    public string  $name                = '';
    public string  $titleTemplate       = '{{title}}';
    public string  $descriptionTemplate = '{{description}}';
    public bool    $isDefault           = false;
    public ?string $createdAt           = null;
    public ?string $updatedAt           = null;
}
