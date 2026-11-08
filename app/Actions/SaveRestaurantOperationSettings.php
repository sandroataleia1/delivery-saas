<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\ProductionSector;
use App\Models\RestaurantOperationSettings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveRestaurantOperationSettings
{
    public function handle(Company $company, array $data): void
    {
        DB::transaction(function () use ($company, $data) {
            $company = Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $row = RestaurantOperationSettings::withoutGlobalScopes()->where('company_id', $company->id)->first() ?? new RestaurantOperationSettings;
            if ($data['automatic_enabled'] ?? false) {
                Gate::authorize('company.manage');
                abort_unless(Auth::user()->company_id === $company->id, 403);
                if (! ($data['remote_risk_accepted'] ?? false)) {
                    throw ValidationException::withMessages(['remote_risk_accepted' => 'Aceite o risco de pedidos remotos.']);
                }
                if (! ProductionSector::where('company_id', $company->id)->where('active', true)->whereKey($company->default_production_sector_id)->exists()) {
                    throw ValidationException::withMessages(['automatic_enabled' => 'Configure um setor padrão ativo antes de ativar.']);
                }
                $data['automatic_accepted_at'] = now();
                $data['automatic_accepted_by_id'] = Auth::id();
            }
            unset($data['remote_risk_accepted']);
            $row->forceFill(['company_id' => $company->id, ...$data])->save();
        });
    }
}
