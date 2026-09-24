<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Services\DelegationService;
use App\Models\User;

class RevokeDelegationAction
{
    public function __construct(private DelegationService $delegations) {}

    public function handle(Delegation $delegation, User $revokedBy): Delegation
    {
        if ($delegation->revoked_at === null) {
            $delegation->forceFill([
                'revoked_at' => now(),
                'revoked_by' => $revokedBy->id,
            ])->save();
        }

        $this->delegations->flush();

        return $delegation;
    }
}
