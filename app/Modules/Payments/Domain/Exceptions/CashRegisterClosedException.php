<?php

namespace App\Modules\Payments\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

/**
 * R-PAY-06 : la caisse du trésorier est clôturée pour le jour métier — aucun nouvel
 * encaissement cash n'est accepté. La réouverture admin n'existe pas encore.
 */
class CashRegisterClosedException extends BusinessException
{
    public function __construct(string $date)
    {
        parent::__construct(
            "Votre caisse est clôturée pour la journée du {$date} : aucun nouvel encaissement en espèces n'est possible aujourd'hui.",
            'cash_register_closed',
            409,
        );
    }
}
