<?php

namespace App\Modules\Tontine\Domain\Enums;

/**
 * Cycle de vie d'un retrait anticipé (R-WDR-03).
 *
 *   pending → under_review → info_requested ⇄ under_review → approved
 *   approved → payout_processing → disbursed | payout_failed → payout_processing (relance)
 *   rejected  : depuis tout état avant le lancement du versement (motif obligatoire)
 *   cancelled : depuis pending / info_requested (membre ou staff)
 *
 * L'approbation administrative est distincte du versement : seul `disbursed`
 * (versement confirmé) correspond à une écriture comptable.
 */
enum EarlyWithdrawalStatus: string
{
    case Pending          = 'pending';
    case UnderReview      = 'under_review';
    case InfoRequested    = 'info_requested';
    case Approved         = 'approved';
    case PayoutProcessing = 'payout_processing';
    case PayoutFailed     = 'payout_failed';
    case Disbursed        = 'disbursed';
    case Rejected         = 'rejected';
    case Cancelled        = 'cancelled';

    private const TRANSITIONS = [
        'pending'           => ['under_review', 'info_requested', 'approved', 'rejected', 'cancelled'],
        'under_review'      => ['info_requested', 'approved', 'rejected', 'cancelled'],
        'info_requested'    => ['under_review', 'rejected', 'cancelled'],
        'approved'          => ['payout_processing', 'rejected'],
        'payout_processing' => ['disbursed', 'payout_failed'],
        'payout_failed'     => ['payout_processing', 'rejected'],
        'disbursed'         => [],
        'rejected'          => [],
        'cancelled'         => [],
    ];

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->value], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending          => 'En attente',
            self::UnderReview      => 'En cours d\'analyse',
            self::InfoRequested    => 'Informations requises',
            self::Approved         => 'Approuvé',
            self::PayoutProcessing => 'Versement en cours',
            self::PayoutFailed     => 'Échec du versement',
            self::Disbursed        => 'Versé',
            self::Rejected         => 'Refusé',
            self::Cancelled        => 'Annulé',
        };
    }

    /** Statuts qui réservent l'épargne (déduits du disponible) : tous les non terminaux. */
    public static function committed(): array
    {
        return [
            self::Pending->value, self::UnderReview->value, self::InfoRequested->value,
            self::Approved->value, self::PayoutProcessing->value, self::PayoutFailed->value,
        ];
    }

    /** Statuts rejetés automatiquement à la clôture de la campagne (D2, R-CAMP-15). */
    public static function rejectedOnClosure(): array
    {
        return [
            self::Pending->value, self::UnderReview->value, self::InfoRequested->value,
            self::Approved->value, self::PayoutFailed->value,
        ];
    }

    /** File d'instruction (avant décision). */
    public static function awaitingDecision(): array
    {
        return [self::Pending->value, self::UnderReview->value, self::InfoRequested->value];
    }
}
