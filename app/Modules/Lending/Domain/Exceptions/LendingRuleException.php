<?php

namespace App\Modules\Lending\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

/**
 * Règle métier Lending violée. Remplace les RuntimeException (rendues en 500)
 * par des erreurs métier explicites (4xx, code stable).
 */
class LendingRuleException extends BusinessException
{
    public static function invalidRequestTransition(string $message): self
    {
        return new self($message, 'invalid_loan_request_transition', 422);
    }

    public static function makerChecker(): self
    {
        return new self('Le même utilisateur ne peut pas approuver et contre-signer.', 'maker_checker_violation', 422);
    }

    public static function loanNotActive(): self
    {
        return new self('Ce prêt n\'est plus actif.', 'loan_not_active', 422);
    }

    public static function membershipNotActive(): self
    {
        return new self('Une avance sur épargne exige une adhésion active.', 'membership_not_active', 422);
    }

    public static function campaignNotActive(): self
    {
        return new self('La campagne n\'est pas active : aucune avance ne peut y être demandée ni décaissée.', 'campaign_not_active', 422);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('Cette clé de requête a déjà servi pour une autre opération.', 'idempotency_key_reused', 409);
    }

    public static function amountExceedsDue(int $amount, int $due): self
    {
        return new self("Montant ({$amount} XOF) supérieur au reste dû ({$due} XOF).", 'repayment_exceeds_due', 422);
    }
}
