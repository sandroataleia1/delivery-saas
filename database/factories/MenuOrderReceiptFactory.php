<?php

namespace Database\Factories;

use App\Actions\OpenTable;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MenuOrderReceipt>
 */
class MenuOrderReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dining_table_id' => DiningTable::factory(),
            'company_id' => fn (array $attributes) => DiningTable::withoutGlobalScopes()->findOrFail($attributes['dining_table_id'])->company_id,
            'service_context' => fn (array $attributes) => DiningTable::withoutGlobalScopes()->findOrFail($attributes['dining_table_id'])->service_context,
            'table_order_id' => fn (array $attributes) => app(OpenTable::class)->handle(DiningTable::withoutGlobalScopes()->findOrFail($attributes['dining_table_id']), 1, null, 'Cliente (QR Code)')->id,
            'client_uuid' => (string) Str::uuid(),
            'content_hash' => hash('sha256', fake()->uuid()),
            'tracking_key_hash' => hash('sha256', fake()->uuid()),
            'status' => MenuOrderReceipt::PENDING, 'units' => 1, 'total' => '10.00', 'policy_snapshot' => config('restaurant.defaults'),
        ];
    }
}
