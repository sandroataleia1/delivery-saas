<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use App\Support\PublicImage;
use Database\Factories\OnlineStoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OnlineStore extends Model
{
    /** @use HasFactory<OnlineStoreFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'accepts_delivery' => 'boolean',
            'accepts_pickup' => 'boolean',
            'min_order' => 'decimal:2',
            'accept_minutes' => 'integer',
            'paused_at' => 'datetime',
        ];
    }

    public function hours(): HasMany
    {
        return $this->hasMany(OnlineStoreHour::class, 'company_id', 'company_id')->orderBy('weekday')->orderBy('opens_at');
    }

    public function logoUrl(): ?string
    {
        return PublicImage::url($this->logo_path);
    }

    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }

    public function auditLabel(): string
    {
        return 'Loja online';
    }
}
