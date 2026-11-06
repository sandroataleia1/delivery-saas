<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'permissions'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Todas as chaves de permissão do catálogo. */
    public static function allPermissions(): array
    {
        return collect(config('permissions.groups'))
            ->flatMap(fn (array $group) => array_keys($group['permissions']))
            ->all();
    }

    public function grantedPermissions(): array
    {
        return $this->is_admin ? self::allPermissions() : ($this->permissions ?? []);
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return $field === 'permissions' && is_string($value) ? json_decode($value, true) : $value;
    }
}
