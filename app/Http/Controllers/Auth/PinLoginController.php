<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Services\PinLockout;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\System\Services\Settings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Quick PIN sign-in, only available on registered terminals.
 */
class PinLoginController extends Controller
{
    public function create(CurrentTerminal $currentTerminal, Settings $settings): View
    {
        return view('auth.pin-login', [
            'terminal' => $currentTerminal->get(),
            'clockInPhoto' => (bool) $settings->get('hr.clock_in_photo', false),
            // Accounts with two-factor authentication sign in with password + code only.
            'users' => User::query()
                ->active()
                ->whereNotNull('pin_hash')
                ->where(fn ($query) => $query->whereNull('two_factor_secret')->orWhereNull('two_factor_confirmed_at'))
                ->with('roles')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, CurrentTerminal $currentTerminal, PinLockout $lockout): RedirectResponse
    {
        $pin = config('pos.pin');

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'pin' => ['required', 'digits_between:'.$pin['min_length'].','.$pin['max_length']],
            // Webcam snapshot for the clock-in (Settings → HR & payroll); checked again when stored.
            'photo' => ['nullable', 'string', 'max:1000000'],
        ]);

        $terminal = $currentTerminal->get();
        $throttleKey = 'pin-login:'.$terminal?->id.':'.$validated['user_id'];

        if (RateLimiter::tooManyAttempts($throttleKey, $pin['max_attempts_per_minute'])) {
            throw ValidationException::withMessages([
                'pin' => 'Too many attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        $user = User::query()->active()->find($validated['user_id']);

        // A PIN alone must not get round the second factor.
        if ($user !== null && $user->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['pin' => 'This account uses two-factor authentication. Sign in with your username and password.']);
        }

        if ($user !== null) {
            $lockout->ensureUnlocked($user);
        }

        if ($user === null || ! $user->checkPin($validated['pin'])) {
            RateLimiter::hit($throttleKey, 60);

            if ($user !== null) {
                $lockout->failed($user, 'login_failed', 'Failed sign-in');
            }

            throw ValidationException::withMessages(['pin' => 'Incorrect PIN.']);
        }

        RateLimiter::clear($throttleKey);
        $lockout->clear($user);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
