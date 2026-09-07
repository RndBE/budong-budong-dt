<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        | Every ability in config/access.php is answered from the signed-in
        | user's role. Returning null instead of false leaves anything the
        | catalogue does not cover to Laravel's own gates and policies.
        */
        Gate::before(fn (User $user, string $ability) => $user->hasPermission($ability) ?: null);
    }
}
