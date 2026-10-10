<?php

namespace App\Domain\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Checks a user's PIN on a terminal (re-authentication during a handover), with the
 * same lock-outs as PIN sign-in: per minute, and per day (PinLockout).
 */
class PinVerifier
{
    public function __construct(private readonly PinLockout $lockout) {}

    /**
     * @throws ValidationException
     */
    public function verify(User $user, string $pin, ?int $terminalId, string $field = 'pin'): void
    {
        $limit = (int) config('pos.pin.max_attempts_per_minute', 5);
        $key = "pin-verify:{$terminalId}:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw ValidationException::withMessages([$field => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $this->lockout->ensureUnlocked($user, $field);

        if (! $user->is_active || ! $user->checkPin($pin)) {
            RateLimiter::hit($key, 60);
            $this->lockout->failed($user, 'pin_failed', 'Wrong PIN at a cashier handover');

            throw ValidationException::withMessages([$field => "Incorrect PIN for {$user->name}."]);
        }

        RateLimiter::clear($key);
        $this->lockout->clear($user);
    }
}
