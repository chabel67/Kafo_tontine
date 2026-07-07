<?php

namespace App\Modules\Payments\Domain\Dto;

/**
 * Résultat d'une vérification server-to-server auprès du PSP.
 * Utilisé en défense en profondeur au webhook (R-PAY-01 : PSP = source de vérité).
 */
final readonly class PspVerification
{
    public function __construct(
        public string  $transactionId,
        public bool    $isSuccess,
        public int     $amountMinor,
        public string  $currency,
        public ?string $method = null,
        public ?string $performedAt = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array   $raw = [],
    ) {}
}
