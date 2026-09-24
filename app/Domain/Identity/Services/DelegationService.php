<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Delegation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Answers "does this user currently hold a delegated permission?".
 *
 * Registered as a scoped singleton so the lookup runs once per request.
 */
class DelegationService
{
    /**
     * @var array<int, Collection<int, Delegation>>
     */
    private array $cache = [];

    /**
     * Delegations to the user that have not been revoked or expired.
     *
     * @return Collection<int, Delegation>
     */
    public function activeFor(User $user): Collection
    {
        $this->cache[$user->id] ??= Delegation::query()
            ->where('to_user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->get();

        return $this->cache[$user->id]->filter(
            fn (Delegation $delegation): bool => $delegation->isActiveAt(now()),
        )->values();
    }

    public function grants(User $user, string $permission): bool
    {
        return $this->activeFor($user)->contains(
            fn (Delegation $delegation): bool => $delegation->grants($permission),
        );
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
