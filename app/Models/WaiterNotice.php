<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WaiterNoticeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Aviso para o app do garçom (pedido pelo QR, item pronto). Cada garçom tem a sua cópia com lido/não lido.
 */
class WaiterNotice extends Model
{
    /** @use HasFactory<WaiterNoticeFactory> */
    use BelongsToCompany, HasFactory, MassPrunable;

    public const MENU_ORDER = 'menu_order';

    public const READY = 'ready';

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    /** Avisos com mais de 3 dias saem do banco: o garçom só precisa do que perdeu no turno. */
    public function prunable(): Builder
    {
        return static::withoutGlobalScopes()->where('created_at', '<', now()->subDays(3));
    }
}
