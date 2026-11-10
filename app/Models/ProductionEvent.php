<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ProductionEvent extends Model
{
    use Auditable, BelongsToCompany;

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }
}
