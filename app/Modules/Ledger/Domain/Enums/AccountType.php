<?php

namespace App\Modules\Ledger\Domain\Enums;

enum AccountType: string
{
    case MemberSavings       = 'MEMBER_SAVINGS';
    case CashBox             = 'CASH_BOX';
    case MomoFloat           = 'MOMO_FLOAT';
    case Equity              = 'EQUITY';
    case RevenueInterest     = 'REVENUE_INTEREST';
    case PenaltyIncome       = 'PENALTY_INCOME';
    case LoanReceivable      = 'LOAN_RECEIVABLE';
    case ExpenseLoss         = 'EXPENSE_LOSS';
    // Opérations manuelles staff (R-LEDGER-09/10) — charges & recettes hors flows métier.
    case OperationalExpense  = 'OPERATIONAL_EXPENSE';
    case MiscRevenue         = 'MISC_REVENUE';
    // Frais d'adhésion à une campagne (R-FEE-02) — produit, jamais de l'épargne membre.
    case FeeIncome           = 'FEE_INCOME';
    // Caisse commune d'une campagne rotative : fonds collectés à redistribuer (passif).
    // Clé ROTATING_POOL:{campaign_id}. Jamais confondue avec MEMBER_SAVINGS.
    case RotatingPool        = 'ROTATING_POOL';

    public function label(): string
    {
        return match($this) {
            self::MemberSavings      => 'Épargne membre',
            self::CashBox            => 'Caisse physique',
            self::MomoFloat          => 'Float Mobile Money',
            self::Equity             => 'Capitaux propres',
            self::RevenueInterest    => 'Revenus intérêts',
            self::PenaltyIncome      => 'Revenus pénalités',
            self::LoanReceivable     => 'Prêts à recouvrer',
            self::ExpenseLoss        => 'Pertes (write-off)',
            self::OperationalExpense => 'Dépenses opérationnelles',
            self::MiscRevenue        => 'Recettes diverses',
            self::FeeIncome          => 'Produits de frais (adhésion, tontine)',
            self::RotatingPool       => 'Caisse tontine rotative',
        };
    }

    /**
     * Comptes à solde débiteur (actifs ET charges) : solde = débits − crédits.
     * Les charges (pertes, dépenses) s'affichaient en négatif quand seul isAsset() était utilisé.
     */
    public function isDebitNormal(): bool
    {
        return $this->isAsset() || in_array($this, [self::ExpenseLoss, self::OperationalExpense], true);
    }

    /** Comptes d'actif : solde = débits - crédits */
    public function isAsset(): bool
    {
        return in_array($this, [
            self::CashBox, self::MomoFloat, self::LoanReceivable,
        ]);
    }
}
