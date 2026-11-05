<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DiningTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['number', 'area', 'seats'])]
class DiningTable extends Model
{
    /** @use HasFactory<DiningTableFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function openOrder(): HasOne
    {
        return $this->hasOne(TableOrder::class)->where('status', TableOrder::OPEN);
    }

    public function auditLabel(): string
    {
        return "Mesa {$this->number}";
    }
}
