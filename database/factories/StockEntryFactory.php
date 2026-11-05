<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\StockEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockEntry>
 */
class StockEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'entry_date' => today(),
            'user_name' => 'Sistema',
            'status' => StockEntry::CONFIRMED,
        ];
    }
}
