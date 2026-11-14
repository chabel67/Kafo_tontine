<?php

namespace App\Modules\Payments\Domain\Contracts;

use App\Modules\Payments\Domain\Dto\CheckoutContext;
use App\Modules\Payments\Domain\Dto\PspVerification;
use App\Modules\Payments\Domain\Dto\PspWebhookEvent;
use App\Modules\Payments\Domain\Enums\PaymentChannel;
use App\Modules\Payments\Domain\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Infrastructure\Models\Payment;
use Illuminate\Http\Request;

/**
 * Agrégateur de paiement (DA-20) : KKiaPay, FedaPay… Un driver par agrégateur,
 * résolu par `PspRegistry`. Encaissement uniquement : les versements restent manuels.
 */
interface PspDriver
{
    public function channel(): PaymentChannel;

    /** Clés nécessaires présentes (sans elles, l'agrégateur ne peut pas être activé). */
    public function isConfigured(): bool;

    /** @return list<string> réseaux couverts (valeurs `MobileNetwork`), pour l'affichage. */
    public function networks(): array;

    public function isSandbox(): bool;

    /**
     * Paramètres que le front utilise pour ouvrir le paiement : widget côté client
     * (KKiaPay, mode `widget`) ou page de paiement de l'agrégateur (FedaPay, mode `redirect`).
     */
    public function prepareCheckout(Payment $payment): CheckoutContext;

    /** État d'une transaction, server-to-server (défense en profondeur, R-PAY-01). */
    public function verify(string $transactionId): PspVerification;

    /**
     * Vérifie la signature du webhook et en extrait l'événement.
     *
     * @throws InvalidWebhookSignatureException
     */
    public function parseWebhook(Request $request): PspWebhookEvent;
}
