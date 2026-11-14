<?php

namespace App\Modules\Payments\Domain\Dto;

/**
 * Notification d'un agrégateur, signature déjà vérifiée par le driver.
 * Le webhook n'est jamais cru sur parole : l'état est re-vérifié par `PspDriver::verify()`.
 */
final readonly class PspWebhookEvent
{
    public function __construct(
        public string  $transactionId,
        /** null = événement sans issue (créé, en attente…) : on accuse réception sans rien faire. */
        public ?bool   $claimedSuccess,
        public ?string $paymentId = null,
        public ?string $idempotencyKey = null,
        public ?string $method = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public ?string $eventName = null,
    ) {}
}
