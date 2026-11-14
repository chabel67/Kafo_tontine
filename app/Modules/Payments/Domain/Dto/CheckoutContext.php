<?php

namespace App\Modules\Payments\Domain\Dto;

/**
 * Contexte à retourner au front pour ouvrir le paiement chez l'agrégateur.
 * `mode = widget` : SDK côté client (KKiaPay) ; `mode = redirect` : ouvrir `checkout_url` (FedaPay).
 * Le front consomme les champs dont son SDK a besoin (openKkiapayWidget côté web,
 * KKiapay-Flutter côté mobile). `data` transporte l'identifiant Payment interne
 * pour la réconciliation au webhook.
 */
final readonly class CheckoutContext
{
    public function __construct(
        public string  $provider,
        public string  $publicKey,
        public int     $amountMinor,
        public string  $currency,
        public bool    $sandbox,
        public array   $data,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $fullName = null,
        public ?string $callbackUrl = null,
        public ?string $reason = null,
        public ?array  $restrictPaymentMethods = null,
        /** `widget` (SDK côté client, KKiaPay) ou `redirect` (page de l'agrégateur, FedaPay). */
        public string  $mode = 'widget',
        public ?string $checkoutUrl = null,
        public ?string $transactionId = null,
    ) {}

    public function toArray(): array
    {
        return [
            'provider'                 => $this->provider,
            'public_key'               => $this->publicKey,
            'amount'                   => $this->amountMinor,
            'currency'                 => $this->currency,
            'sandbox'                  => $this->sandbox,
            'data'                     => $this->data,
            'phone'                    => $this->phone,
            'email'                    => $this->email,
            'fullname'                 => $this->fullName,
            'callback'                 => $this->callbackUrl,
            'reason'                   => $this->reason,
            'restrict_payment_methods' => $this->restrictPaymentMethods,
            'mode'                     => $this->mode,
            'checkout_url'             => $this->checkoutUrl,
            'transaction_id'           => $this->transactionId,
        ];
    }
}
