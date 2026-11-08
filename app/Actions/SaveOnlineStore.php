<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SaveOnlineStore
{
    /**
     * @param  array<string, mixed>  $data  Campos da loja e, em `hours`, o conjunto completo de turnos.
     */
    public function handle(array $data): OnlineStore
    {
        return DB::transaction(function () use ($data) {
            $companyId = Auth::user()->company_id;
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

            $hours = $data['hours'] ?? [];
            unset($data['hours']);

            $store = OnlineStore::where('company_id', $companyId)->first() ?? new OnlineStore;
            $store->forceFill([...$data, 'company_id' => $companyId])->save();

            $old = OnlineStoreHour::where('company_id', $companyId)->get(['weekday', 'opens_at', 'closes_at'])->toArray();
            OnlineStoreHour::where('company_id', $companyId)->delete();
            $rows = collect($hours)->map(fn (array $shift) => [
                'company_id' => $companyId,
                'weekday' => (int) $shift['weekday'],
                'opens_at' => $shift['opens_at'].':00',
                'closes_at' => $shift['closes_at'].':00',
            ])->all();
            OnlineStoreHour::insert($rows);

            if ($old !== [] || $rows !== []) {
                Audit::record(
                    'hours_updated',
                    $store,
                    'Horários da loja online atualizados.',
                    old: ['hours' => $old],
                    new: ['hours' => array_map(fn (array $row) => collect($row)->only(['weekday', 'opens_at', 'closes_at'])->all(), $rows)],
                );
            }

            return $store;
        });
    }
}
