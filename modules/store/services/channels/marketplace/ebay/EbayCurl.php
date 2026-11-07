<?php
declare(strict_types=1);

namespace Module\Store\Services\Channels\Marketplace\Ebay;

use Module\Store\Services\Channels\CurlSsl;

/** @deprecated Use CurlSsl — kept for existing call sites. */
final class EbayCurl
{
    /**
     * @param array<int, mixed> $options
     * @return array<int, mixed>
     */
    public static function withSsl(array $options): array
    {
        return CurlSsl::withSsl($options);
    }
}
