<?php

namespace App\Modules\Ledger\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

/** R-LEDGER-01 : une transaction ne se contre-passe qu'une seule fois (index unique sur reverses_id). */
class TransactionAlreadyReversedException extends BusinessException
{
    public function __construct(string $reference)
    {
        parent::__construct("La transaction {$reference} a déjà été contre-passée.", 'transaction_already_reversed', 409);
    }
}
