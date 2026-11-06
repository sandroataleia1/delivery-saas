<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\DiningTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['number', 'area', 'seats'])]
class DiningTable extends Model
{
    /** @use HasFactory<DiningTableFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected static function booted(): void
    {
        static::creating(function (DiningTable $table) {
            $table->qr_token ??= Str::random(32);
        });
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function openOrder(): HasOne
    {
        return $this->hasOne(TableOrder::class)->where('status', TableOrder::OPEN);
    }

    protected function auditExclude(): array
    {
        return ['qr_token'];
    }

    public function auditLabel(): string
    {
        return "Mesa {$this->number}";
    }
}
