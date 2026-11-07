<?php
declare(strict_types=1);

namespace System\Engine;

use RuntimeException;
use Throwable;

class NotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Not Found', ?Throwable $previous = null)
    {
        parent::__construct($message, 404, $previous);
    }
}
