<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $username = Str::lower(trim((string) $request->input('username')));
            $user = User::query()->where('username', $username)->first();

            $failure = match (true) {
                $user === null => 'unknown username',
                ! $user->is_active => 'inactive account',
                ! Hash::check((string) $request->input('password'), $user->password) => 'wrong password',
                default => null,
            };

            if ($failure !== null) {
                activity()
                    ->event('login_failed')
                    ->withProperties(['username' => Str::limit($username, 60), 'reason' => $failure, 'ip' => $request->ip()])
                    ->log('Failed sign-in');

                return null;
            }

            $user->forceFill(['last_login_at' => now()])->saveQuietly();
            activity()->causedBy($user)->performedOn($user)->event('login')->log('Signed in with password');

            return $user;
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
