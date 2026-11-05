<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'document', 'email', 'phone', 'notes'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
