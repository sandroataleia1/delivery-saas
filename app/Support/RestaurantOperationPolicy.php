<?php

namespace App\Support;

use App\Models\Company;
use App\Models\ProductionSector;
use App\Models\RestaurantOperationSettings;

class RestaurantOperationPolicy
{
    public function resolve(Company $company): array
    {
        $row = RestaurantOperationSettings::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $settings = [...config('restaurant.defaults'), ...($row?->only(array_keys(config('restaurant.defaults'))) ?? [])];
        $default = Company::findOrFail($company->id)->default_production_sector_id;
        $available = ProductionSector::withoutGlobalScopes()->where('company_id', $company->id)->whereKey($default)->where('active', true)->exists();
        $reason = match (true) {
            (bool) $row?->automatic_blocked => 'platform_block',
            $settings['confirmation_mode'] !== 'automatic' => 'manual_preference',
            ! $row?->automatic_enabled || ! $row?->automatic_accepted_at => 'production_unavailable',
            ! $available => 'destination_unavailable',
            default => 'automatic_enabled',
        };

        return [...$settings, 'automatic_blocked' => $row?->automatic_blocked ?? false,
            'automatic_enabled' => $row?->automatic_enabled ?? false, 'automatic_accepted_at' => $row?->automatic_accepted_at,
            'effective_mode' => $reason === 'automatic_enabled' ? 'automatic' : 'manual', 'effective_reason' => $reason];
    }
}
