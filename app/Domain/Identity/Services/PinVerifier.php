<?php

namespace App\Domain\Identity\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Checks a user's PIN on a terminal (re-authentication during a handover), with the
 * same lock-out as PIN sign-in.
 */
class PinVerifier
{
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

        if (! $user->is_active || ! $user->checkPin($pin)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([$field => "Incorrect PIN for {$user->name}."]);
        }

        RateLimiter::clear($key);
    }
}
