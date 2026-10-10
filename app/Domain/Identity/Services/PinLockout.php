<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Notifications\PinLocked;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A daily limit on wrong PINs per user, on top of the per-minute limit, so nobody can
 * find a PIN by trying every number at a terminal over a day or two.
 *
 * Every wrong PIN is written to the audit log. When an account reaches the limit its PIN
 * stops working until midnight (or until the owner sets a new PIN) and the Super Admins
 * get a notification.
 */
class PinLockout
{
    public function __construct(private readonly CurrentTerminal $currentTerminal) {}

    /**
     * @throws ValidationException
     */
    public function ensureUnlocked(User $user, string $field = 'pin'): void
    {
        if (RateLimiter::tooManyAttempts($this->key($user), $this->maxPerDay())) {
            throw ValidationException::withMessages([
                $field => "The PIN of {$user->name} is locked for today after too many wrong PINs. Sign in with the password, or ask the owner to set a new PIN.",
            ]);
        }
    }

    /**
     * A wrong PIN: count it, log it, and lock the PIN when the daily limit is reached.
     *
     * @param  string  $event  audit log event: login_failed (PIN sign-in) or pin_failed (handover)
     */
    public function failed(User $user, string $event, string $description): void
    {
        $key = $this->key($user);
        RateLimiter::hit($key, 86400);

        $properties = array_filter([
            'username' => $user->username,
            'method' => 'pin',
            'reason' => 'wrong PIN',
            'terminal' => $this->currentTerminal->get()?->code,
            'ip' => request()->ip(),
        ]);

        activity()->performedOn($user)->event($event)->withProperties($properties)->log($description);

        if (RateLimiter::attempts($key) === $this->maxPerDay()) {
            activity()->performedOn($user)->event('pin_locked')->withProperties($properties)->log('PIN locked for today');

            Notification::send(
                User::query()->active()->role(Role::SuperAdmin->value)->get(),
                PinLocked::for($user, $this->currentTerminal->get()?->displayName()),
            );
        }
    }

    /**
     * Correct PIN, or the owner set a new one.
     */
    public function clear(User $user): void
    {
        RateLimiter::clear($this->key($user));
    }

    public function maxPerDay(): int
    {
        return (int) config('pos.pin.max_failures_per_day', 15);
    }

    private function key(User $user): string
    {
        return 'pin-failures:'.$user->id.':'.today()->format('Ymd');
    }
}
