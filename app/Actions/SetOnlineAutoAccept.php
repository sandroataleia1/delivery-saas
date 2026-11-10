<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\ProductionSector;
use App\Support\Audit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Liga ou desliga o aceite automático da loja online. Ligar exige o setor de produção padrão ativo. */
class SetOnlineAutoAccept
{
    public function handle(bool $enabled): OnlineStore
    {
        return DB::transaction(function () use ($enabled) {
            $company = Company::whereKey(Auth::user()->company_id)->lockForUpdate()->firstOrFail();
            $store = OnlineStore::where('company_id', $company->id)->firstOrFail();

            $hasSector = ProductionSector::where('company_id', $company->id)->where('active', true)->whereKey($company->default_production_sector_id)->exists();
            if ($enabled && ! $hasSector) {
                throw ValidationException::withMessages(['auto_accept' => 'Cadastre um setor de produção padrão ativo antes de ativar o aceite automático.']);
            }

            $store->forceFill(['auto_accept' => $enabled])->save();
            Audit::record($enabled ? 'auto_accept_on' : 'auto_accept_off', $store, $enabled ? 'Aceite automático ligado.' : 'Aceite automático desligado.');

            return $store;
        });
    }
}
