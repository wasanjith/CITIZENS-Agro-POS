<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\Terminal;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Binds a browser to a terminal with a random device token.
 *
 * Only the SHA-256 hash of the token is stored; the plain token lives in an
 * encrypted, httpOnly cookie on the device.
 */
class TerminalRegistrar
{
    /**
     * Register the device and return the plain token to store in the cookie.
     * Any previously registered device for this terminal stops working.
     */
    public function register(Terminal $terminal, User $registeredBy): string
    {
        $token = Str::random(64);

        $terminal->forceFill([
            'device_token_hash' => self::hash($token),
            'registered_at' => now(),
            'registered_by' => $registeredBy->id,
        ])->save();

        return $token;
    }

    public function unregister(Terminal $terminal): void
    {
        $terminal->forceFill([
            'device_token_hash' => null,
            'registered_at' => null,
            'registered_by' => null,
        ])->save();
    }

    public function resolve(?string $token): ?Terminal
    {
        if ($token === null || $token === '') {
            return null;
        }

        return Terminal::query()
            ->active()
            ->where('device_token_hash', self::hash($token))
            ->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
