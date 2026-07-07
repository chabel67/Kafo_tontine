<?php

namespace App\Modules\Payments\Domain\Contracts;

use App\Modules\Payments\Domain\Dto\CheckoutContext;
use App\Modules\Payments\Domain\Dto\PspVerification;
use App\Modules\Payments\Infrastructure\Models\Payment;

interface PspDriver
{
    /**
     * Prépare les paramètres à retourner au front pour ouvrir le widget PSP.
     * Aucun appel réseau côté serveur — le paiement n'est initié qu'une fois
     * le widget confirmé côté client (KKiaPay = agrégateur).
     */
    public function prepareCheckout(Payment $payment): CheckoutContext;

    /**
     * Vérifie l'état d'une transaction auprès du PSP (server-to-server).
     * Appelé au webhook comme défense en profondeur du secret partagé.
     */
    public function verify(string $transactionId): PspVerification;
}
