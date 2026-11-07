<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\RestaurantOperationSettings;
use Illuminate\Support\Facades\DB;

class SaveRestaurantOperationSettings
{
    public function handle(Company $company, array $data): void
    {
        DB::transaction(function () use ($company, $data) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $row = RestaurantOperationSettings::withoutGlobalScopes()->where('company_id', $company->id)->first() ?? new RestaurantOperationSettings;
            $row->forceFill(['company_id' => $company->id, ...$data])->save();
        });
    }
}
