<?php

namespace App\Modules\Tontine\Http\Requests;

/**
 * Modification des informations personnelles d'un membre — mêmes règles que la création,
 * unicité téléphone/email hors membre courant.
 */
class UpdateMemberRequest extends StoreMemberRequest
{
    protected function memberId(): ?string
    {
        return $this->route('user')?->id;
    }
}
