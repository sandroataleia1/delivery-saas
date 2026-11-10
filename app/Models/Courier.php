<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CourierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entregador da loja. Com usuário vinculado, ele vê as próprias entregas no celular. */
#[Fillable(['name', 'phone', 'active', 'user_id'])]
class Courier extends Model
{
    /** @use HasFactory<CourierFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quem pode levar entregas ao despachar: o cadastro ativo e, para não quebrar
     * o que já funcionava, os usuários entregadores ainda sem cadastro vinculado.
     *
     * @return list<array{type: string, id: int, name: string}>
     */
    public static function dispatchOptions(): array
    {
        $couriers = self::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'user_id']);
        $linkedUsers = self::query()->whereNotNull('user_id')->pluck('user_id')->all();

        $users = collect(User::activeCouriers())
            ->reject(fn (array $user) => in_array($user['id'], $linkedUsers, true))
            ->map(fn (array $user) => ['type' => 'user', 'id' => $user['id'], 'name' => $user['name']]);

        return $couriers->map(fn (Courier $courier) => ['type' => 'courier', 'id' => $courier->id, 'name' => $courier->name])
            ->concat($users)
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
