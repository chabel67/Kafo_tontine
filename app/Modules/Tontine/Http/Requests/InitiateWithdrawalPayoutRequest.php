<?php

namespace App\Modules\Tontine\Http\Requests;

/**
 * Lancement d'une tentative de versement : canal + numéro (mêmes règles que le payout de clôture)
 * et clé d'idempotence par tentative (un double clic ne crée pas deux tentatives).
 */
class InitiateWithdrawalPayoutRequest extends SettleCampaignPayoutRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ];
    }
}
