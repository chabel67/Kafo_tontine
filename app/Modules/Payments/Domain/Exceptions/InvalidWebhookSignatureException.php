<?php

namespace App\Modules\Payments\Domain\Exceptions;

use RuntimeException;

/** Signature (ou secret partagé) d'un webhook agrégateur absente ou invalide. */
class InvalidWebhookSignatureException extends RuntimeException {}
