<?php

namespace App\Providers;

use App\Domain\Identity\Services\CashierAuthority;
use App\Domain\Identity\Services\DelegationService;
use App\Domain\Identity\Support\CurrentTerminal;
use App\Domain\System\Services\Settings;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTerminal::class);
        $this->app->scoped(DelegationService::class);
        $this->app->singleton(Settings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->registerGates();
        $this->shareLayoutData();
    }

    /**
     * Super Admin passes every check. Anyone else may additionally hold a
     * permission through an active Cashier Handover delegation. Returning null
     * falls through to the normal role permissions (spatie/laravel-permission).
     */
    private function registerGates(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->isSuperAdmin()) {
                return true;
            }

            if (app(DelegationService::class)->grants($user, $ability)) {
                return true;
            }

            return null;
        });
    }

    private function shareLayoutData(): void
    {
        View::composer('layouts.app', function (\Illuminate\View\View $view): void {
            $view->with([
                'currentTerminal' => app(CurrentTerminal::class)->get(),
                'cashierHolder' => app(CashierAuthority::class)->holder(),
            ]);
        });
    }
}
