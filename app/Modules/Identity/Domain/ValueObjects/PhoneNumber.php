<?php

namespace App\Modules\Identity\Domain\ValueObjects;

use InvalidArgumentException;

final class PhoneNumber
{
    private readonly string $value;

    public function __construct(string $raw)
    {
        $cleaned = preg_replace('/\s+/', '', $raw);
        if (! preg_match('/^\+\d{7,15}$/', $cleaned)) {
            throw new InvalidArgumentException("Invalid phone number: {$raw}");
        }
        $this->value = $cleaned;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
