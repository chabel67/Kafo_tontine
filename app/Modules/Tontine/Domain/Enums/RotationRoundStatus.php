<?php

namespace App\Modules\Tontine\Domain\Enums;

/**
 * Cycle de vie d'un tour (machine d'état explicite, transitions contrôlées côté serveur).
 *
 *   scheduled → collecting → ready | blocked → approved → processing → completed
 *                                               processing → failed → processing
 *   vacant : tour sans bénéficiaire (départ sans remplaçant, D-R7) ; un remplaçant le rouvre.
 */
enum RotationRoundStatus: string
{
    case Scheduled  = 'scheduled';   // à venir, collecte non commencée
    case Collecting = 'collecting';  // ses échéances sont en cours de collecte
    case Ready      = 'ready';       // date atteinte, groupe entièrement payé
    case Blocked    = 'blocked';     // date atteinte, impayés dans le groupe ou chez le bénéficiaire
    case Approved   = 'approved';    // validé par le personnel (avec motifs d'override éventuels)
    case Processing = 'processing';  // versement lancé, en attente de confirmation
    case Failed     = 'failed';      // versement échoué, nouvelle tentative possible
    case Completed  = 'completed';   // versé, écriture passée
    case Vacant     = 'vacant';      // sans bénéficiaire

    private const TRANSITIONS = [
        'scheduled'  => ['collecting', 'vacant'],
        'collecting' => ['ready', 'blocked', 'vacant'],
        'ready'      => ['approved', 'blocked', 'vacant'],
        'blocked'    => ['ready', 'approved', 'vacant'],
        'approved'   => ['processing', 'ready', 'blocked'],
        'processing' => ['completed', 'failed'],
        'failed'     => ['processing', 'approved'],
        'completed'  => [],
        'vacant'     => ['scheduled', 'collecting'],
    ];

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::TRANSITIONS[$this->value], true);
    }

    /** Un tour verrouillé ne peut plus changer de bénéficiaire. */
    public function isLocked(): bool
    {
        return in_array($this, [self::Approved, self::Processing, self::Failed, self::Completed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Scheduled  => 'À venir',
            self::Collecting => 'Collecte en cours',
            self::Ready      => 'Prêt à verser',
            self::Blocked    => 'Bloqué',
            self::Approved   => 'Validé',
            self::Processing => 'Versement en cours',
            self::Failed     => 'Échec du versement',
            self::Completed  => 'Versé',
            self::Vacant     => 'Sans bénéficiaire',
        };
    }
}
