<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'active'])]
class ProductionSector extends Model
{
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
