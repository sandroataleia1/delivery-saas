<?php

namespace App\Providers;

use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\StockEntry;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
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
        Route::model('table', DiningTable::class);
        Route::model('order', TableOrder::class);
        Route::model('item', TableOrderItem::class);
        Route::model('delivery', DeliveryOrder::class);
        Route::model('zone', DeliveryZone::class);

        // As permissões do catálogo viram abilities: Gate e middleware can:.
        Gate::before(fn (User $user, string $ability) => $user->hasPermission($ability) ? true : null);

        // Validar uma etapa do cadastro é barato; criar uma loja, não.
        RateLimiter::for('signup', fn (Request $request) => $request->isAttemptingPrecognition()
            ? Limit::perMinute(60)->by('precognition|'.$request->ip())
            : Limit::perHour(5)->by('signup|'.$request->ip()));

        // Rota pública: quem abusa do QR Code é barrado sem atrapalhar o salão cheio.
        RateLimiter::for('menu', function (Request $request) {
            $token = (string) $request->route('token');
            $table = DiningTable::withoutGlobalScopes()->where('qr_token', $token)->first(['id', 'company_id']);
            $tableKey = hash('sha256', $token);

            return [Limit::perMinute(20)->by('menu-ip|'.$tableKey.'|'.$request->ip()),
                Limit::perMinute(60)->by('menu-table|'.$tableKey),
                Limit::perMinute(600)->by('menu-company|'.($table?->company_id ?? $tableKey))];
        });
        RateLimiter::for('menu-tracking', fn (Request $request) => Limit::perMinute(120)
            ->by('menu-tracking|'.hash('sha256', (string) $request->route('token').'|'.(string) $request->route('uuid')).'|'.$request->ip()));

        Password::defaults(fn () => Password::min(8)->letters()->numbers());
    }
}
