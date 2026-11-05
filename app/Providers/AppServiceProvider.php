<?php

namespace App\Providers;

use App\Models\StockEntry;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Route::model('entry', StockEntry::class);

        // As permissões do catálogo viram abilities: Gate e middleware can:.
        Gate::before(fn (User $user, string $ability) => $user->hasPermission($ability) ? true : null);

        // Validar uma etapa do cadastro é barato; criar uma loja, não.
        RateLimiter::for('signup', fn (Request $request) => $request->isAttemptingPrecognition()
            ? Limit::perMinute(60)->by('precognition|'.$request->ip())
            : Limit::perHour(5)->by('signup|'.$request->ip()));

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
