<?php

namespace Modules\ASAP\Exceptions;

use Exception;

class AsapException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400
    ) {
        parent::__construct($message);
    }
}
