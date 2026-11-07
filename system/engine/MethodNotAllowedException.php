<?php
declare(strict_types=1);

namespace System\Engine;

use RuntimeException;
use Throwable;

class MethodNotAllowedException extends RuntimeException
{
    /**
     * @param array<int, string> $allowedMethods
     */
    public function __construct(
        string $message = 'Method Not Allowed',
        public readonly array $allowedMethods = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 405, $previous);
    }
}
