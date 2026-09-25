<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\NonDelegablePermissionException;
use App\Domain\Identity\Models\Delegation;
use App\Domain\Identity\Services\DelegationService;
use App\Domain\Identity\Support\PermissionCatalogue;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;

class CreateDelegationAction
{
    public function __construct(private DelegationService $delegations) {}

    /**
     * Grant cashier-authority permissions from one user to another for a limited time.
     *
     * @param  list<string>|null  $permissions  null = the default handover scope
     *
     * @throws NonDelegablePermissionException
     */
    public function handle(
        User $from,
        User $to,
        CarbonInterface $expiresAt,
        ?array $permissions = null,
        ?string $reason = null,
        ?CarbonInterface $startsAt = null,
        ?int $drawerSessionId = null,
    ): Delegation {
        $permissions = array_values(array_unique($permissions ?? PermissionCatalogue::delegable()));

        $rejected = array_values(array_filter(
            $permissions,
            fn (string $permission): bool => ! PermissionCatalogue::isDelegable($permission),
        ));

        if ($rejected !== []) {
            throw NonDelegablePermissionException::for($rejected);
        }

        if ($from->is($to)) {
            throw new DomainException('A user cannot delegate to themselves.');
        }

        if (! $to->is_active) {
            throw new DomainException('Cannot delegate to an inactive user.');
        }

        $startsAt ??= now();

        if ($expiresAt->lessThanOrEqualTo($startsAt)) {
            throw new DomainException('The delegation must expire after it starts.');
        }

        $delegation = Delegation::create([
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'permissions' => $permissions,
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'reason' => $reason,
            'drawer_session_id' => $drawerSessionId,
        ]);

        $this->delegations->flush();

        return $delegation;
    }
}
