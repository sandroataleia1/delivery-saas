<?php

namespace App\Support;

use App\Models\Company;
use App\Models\RestaurantOperationSettings;

class RestaurantOperationPolicy
{
    public function resolve(Company $company): array
    {
        $row = RestaurantOperationSettings::withoutGlobalScopes()->where('company_id', $company->id)->first();
        $settings = [...config('restaurant.defaults'), ...($row?->only(array_keys(config('restaurant.defaults'))) ?? [])];

        return [...$settings, 'automatic_blocked' => $row?->automatic_blocked ?? false,
            'effective_mode' => 'manual',
            'effective_reason' => $row?->automatic_blocked ? 'platform_block' : ($settings['confirmation_mode'] === 'automatic' ? 'production_unavailable' : 'manual_preference')];
    }
}
