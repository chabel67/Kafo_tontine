<?php

namespace App\Modules\Tontine\Domain\Exceptions;

use App\Modules\Identity\Domain\Exceptions\BusinessException;

/**
 * Règle métier Tontine violée (campagnes, adhésions, retraits, clôture, règlement).
 * Un constructeur nommé par règle : le code d'erreur est le contrat avec les fronts.
 */
class TontineRuleException extends BusinessException
{
    public static function notRotating(): self
    {
        return new self('Cette opération est réservée aux tontines rotatives.', 'not_rotating_campaign', 422);
    }

    public static function notForRotatingCampaign(): self
    {
        return new self('Opération indisponible sur une tontine rotative (pas d\'épargne individuelle).', 'not_for_rotating_campaign', 422);
    }

    public static function campaignNotActive(): self
    {
        return new self('La campagne n\'est pas active : cette opération n\'est plus possible.', 'campaign_not_active', 422);
    }

    public static function earlyWithdrawalsDisabled(): self
    {
        return new self('Les retraits anticipés ne sont pas autorisés dans cette campagne.', 'early_withdrawals_disabled', 422);
    }

    public static function withdrawalPayoutInProgress(string $reference): self
    {
        return new self(
            "Le versement du retrait {$reference} est en cours : confirmez-le ou déclarez son échec avant de régler ce payout.",
            'withdrawal_payout_in_progress',
            409,
        );
    }

    public static function membershipFeeUnpaid(int $remainingMinor): self
    {
        return new self(
            "Frais d'adhésion non réglés : il reste {$remainingMinor} XOF à encaisser avant d'activer l'adhésion.",
            'membership_fee_unpaid',
            422,
        );
    }

    public static function hardRuleLocked(array $fields): self
    {
        return new self(
            'Ces paramètres ne sont plus modifiables une fois la campagne ouverte : ' . implode(', ', $fields) . '.',
            'campaign_rule_locked',
            422,
        );
    }

    public static function invalidLoanRepayment(string $message): self
    {
        return new self($message, 'invalid_loan_repayment', 422);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('Cette clé de requête a déjà servi pour une autre opération.', 'idempotency_key_reused', 409);
    }

    public static function campaignNotDeletable(): self
    {
        return new self(
            'Seule une campagne en brouillon, sans adhésion ni opération, peut être supprimée.',
            'campaign_not_deletable',
            422,
        );
    }
}
