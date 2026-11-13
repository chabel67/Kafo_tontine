<?php

namespace App\Modules\Ledger\Domain\Contracts;

/**
 * Fournit le libellé humain des comptes ledger « possédés » par une entité d'un
 * autre module (ex. MEMBER_SAVINGS:{membership_id} → Tontine, LOAN_RECEIVABLE:{loan_id}
 * → Lending). Le module Ledger ne lit jamais les modèles des autres modules (DA-09) :
 * chaque module enregistre son resolver dans ModuleServiceProvider.
 */
interface AccountOwnerLabelResolver
{
    /**
     * @param  string[]  $ids  Suffixes de clés (partie après « TYPE: »)
     * @return array<string, string>  id → libellé (ids inconnus absents)
     */
    public function labels(array $ids): array;
}
