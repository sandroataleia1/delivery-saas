<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

// company_id, role_id e is_super_admin ficam fora de propósito: nunca vêm de atribuição em massa.
#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, BelongsToCompany, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Conta ativa e, para usuários de empresa, empresa ativa. */
    public function canSignIn(): bool
    {
        return $this->active && ($this->is_super_admin || $this->company?->active === true);
    }

    public function permissions(): array
    {
        return $this->role?->grantedPermissions() ?? [];
    }

    /**
     * Usuários ativos que podem levar entregas, para escolher ao despachar.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function activeCouriers(): array
    {
        return self::query()
            ->where('active', true)
            ->with('role')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission('deliveries.courier'))
            ->map(fn (User $user) => $user->only('id', 'name'))
            ->values()
            ->all();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function isCompanyAdmin(): bool
    {
        return $this->role?->is_admin === true;
    }

    /**
     * Impede escalada de privilégio: só se manipula um perfil que não
     * ultrapasse as permissões de quem está agindo.
     */
    public function canHandleRole(?Role $role): bool
    {
        if ($this->isCompanyAdmin() || $role === null) {
            return true;
        }

        return ! $role->is_admin
            && array_diff($role->grantedPermissions(), $this->permissions()) === [];
    }

    public function isLastActiveAdmin(): bool
    {
        return $this->active
            && $this->isCompanyAdmin()
            && self::query()
                ->where('company_id', $this->company_id)
                ->where('active', true)
                ->whereKeyNot($this->getKey())
                ->whereHas('role', fn ($query) => $query->where('is_admin', true))
                ->doesntExist();
    }

    /** Encerra as sessões abertas do usuário, exceto a informada. */
    public function terminateSessions(?string $except = null): void
    {
        DB::table('sessions')
            ->where('user_id', $this->getKey())
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->delete();
    }

    protected function auditExclude(): array
    {
        return ['remember_token', 'last_login_at'];
    }

    protected function auditRedact(): array
    {
        return ['password'];
    }

    protected function auditDisplayValue(string $field, mixed $value): mixed
    {
        return $field === 'role_id' && $value
            ? Role::withoutGlobalScopes()->find($value)?->name ?? $value
            : $value;
    }
}
