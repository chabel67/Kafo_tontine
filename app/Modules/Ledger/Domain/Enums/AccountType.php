<?php

namespace App\Modules\Ledger\Domain\Enums;

enum AccountType: string
{
    case MemberSavings   = 'MEMBER_SAVINGS';
    case CashBox         = 'CASH_BOX';
    case MomoFloat       = 'MOMO_FLOAT';
    case Equity          = 'EQUITY';
    case RevenueInterest = 'REVENUE_INTEREST';
    case PenaltyIncome   = 'PENALTY_INCOME';
    case LoanReceivable  = 'LOAN_RECEIVABLE';
    case ExpenseLoss     = 'EXPENSE_LOSS';

    public function label(): string
    {
        return match($this) {
            self::MemberSavings   => 'Épargne membre',
            self::CashBox         => 'Caisse physique',
            self::MomoFloat       => 'Float Mobile Money',
            self::Equity          => 'Capitaux propres',
            self::RevenueInterest => 'Revenus intérêts',
            self::PenaltyIncome   => 'Revenus pénalités',
            self::LoanReceivable  => 'Prêts à recouvrer',
            self::ExpenseLoss     => 'Pertes (write-off)',
        };
    }

    /** Comptes d'actif : solde = débits - crédits */
    public function isAsset(): bool
    {
        return in_array($this, [
            self::CashBox, self::MomoFloat, self::LoanReceivable,
        ]);
    }
}
