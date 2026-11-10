<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\RestaurantOperationSettingsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RestaurantOperationSettings extends Model
{
    /** @use HasFactory<RestaurantOperationSettingsFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['max_order_value' => 'decimal:2', 'max_pending_value' => 'decimal:2',
            'visual_alerts' => 'boolean', 'sound_alerts' => 'boolean', 'automatic_blocked' => 'boolean',
            'automatic_enabled' => 'boolean', 'automatic_accepted_at' => 'datetime', 'automatic_blocked_at' => 'datetime'];
    }

    public function auditLabel(): string
    {
        return 'Operação do Restaurante';
    }
}
