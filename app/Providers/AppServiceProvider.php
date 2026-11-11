<?php

namespace App\Providers;

use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\OnlineStore;
use App\Models\StockEntry;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use App\Support\BrazilPhone;
use App\Support\Printing\PrintQueue;
use App\Support\WaiterNotifier;
use App\Support\WhatsAppOutbox;
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
        // Uma fila por requisição: junta os itens da produção até a gravação terminar.
        $this->app->scoped(PrintQueue::class);
        $this->app->scoped(WaiterNotifier::class);
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
        // Agente de impressão: consulta a fila a cada poucos segundos; o limite barra tentativa de chave.
        RateLimiter::for('print-agent', fn (Request $request) => Limit::perMinute(240)->by('print-agent|'.$request->ip()));

        RateLimiter::for('menu-tracking', fn (Request $request) => Limit::perMinute(120)
            ->by('menu-tracking|'.hash('sha256', (string) $request->route('token').'|'.(string) $request->route('uuid')).'|'.$request->ip()));

        // Loja online: barra o abuso por IP, por telefone e por empresa sem travar a loja inteira.
        RateLimiter::for('online', function (Request $request) {
            $slug = (string) $request->route('slug');
            $store = OnlineStore::withoutGlobalScopes()->where('slug', $slug)->first(['id', 'company_id']);
            $slugKey = hash('sha256', $slug);
            $phoneInput = $request->input('customer.phone');
            $phone = is_string($phoneInput) ? BrazilPhone::normalize($phoneInput) : null;

            return [Limit::perMinute(10)->by('online-ip|'.$slugKey.'|'.$request->ip()),
                Limit::perMinutes(10, 5)->by('online-phone|'.$slugKey.'|'.($phone ?? $request->ip())),
                Limit::perMinute(300)->by('online-company|'.($store?->company_id ?? $slugKey))];
        });
        RateLimiter::for('online-status', fn (Request $request) => Limit::perMinute(120)
            ->by('online-status|'.hash('sha256', (string) $request->route('slug').'|'.(string) $request->route('token')).'|'.$request->ip()));

        RateLimiter::for('whatsapp-webhook', fn (Request $request) => Limit::perMinute(240)
            ->by('whatsapp-webhook|'.hash('sha256', (string) $request->route('instance'))));

        Password::defaults(fn () => Password::min(8)->letters()->numbers());

        WhatsAppOutbox::register();
    }
}
