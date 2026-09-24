<?php

namespace App\Listeners;

use App\Domain\Identity\Support\CurrentTerminal;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Stamps last_login_at and writes one audit record per sign-in (password or PIN).
 */
class RecordSuccessfulLogin
{
    public function __construct(private CurrentTerminal $currentTerminal) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        activity()
            ->causedBy($user)
            ->performedOn($user)
            ->event('login')
            ->withProperties(array_filter([
                'terminal' => $this->currentTerminal->get()?->code,
                'method' => request()->routeIs('pin-login.store') ? 'pin' : 'password',
            ]))
            ->log('Signed in');
    }
}
