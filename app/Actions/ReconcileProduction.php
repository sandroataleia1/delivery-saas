<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\ProductionWork;
use App\Support\ProductionDestination;
use App\Support\ProductionGuard;
use App\Support\ProductionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReconcileProduction
{
    public function handle(ProductionWork $work, string $observed, string $reason): ProductionWork
    {
        Gate::authorize('production.manage');

        return DB::transaction(function () use ($work, $observed, $reason) {
            $locked = ProductionLock::work($work);
            if (! $locked->legacy || $locked->reconciled_at || $locked->cancelled_at || trim($reason) === ''
                || ! in_array($observed, ['waiting', 'preparing', 'ready', 'delivered'], true)) {
                throw ValidationException::withMessages(['production' => 'Reconciliação exige legado pendente, estágio observado e motivo.']);
            }
            $locked->load('tableItem', 'deliveryItem');
            $item = $locked->tableItem ?? $locked->deliveryItem;
            $sector = (new ProductionDestination)->resolve(Product::where('company_id', $locked->company_id)->findOrFail($item->product_id));
            $locked->forceFill(['production_sector_id' => $sector->id, 'sector_name' => $sector->name,
                'status' => $observed === 'delivered' ? 'ready' : $observed, 'version' => $locked->version + 1,
                'reconciled_at' => now(), 'delivered_at' => $observed === 'delivered' ? now() : null])->save();
            ProductionGuard::event($locked, null, 'reconciled', 'Estágio observado: '.$observed.'. '.$reason);

            return $locked;
        });
    }
}
