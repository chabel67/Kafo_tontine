<?php

namespace App\Modules\Identity\Domain\Exceptions;

use RuntimeException;

abstract class BusinessException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode,
        int $httpStatus = 400,
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->getCode();
    }
}
