<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversa de uma loja com um contato do WhatsApp: estado do bot, carrinho e pausas.
 * Sem escopo global: o bot roda fora de sessão e sempre filtra por company_id.
 */
class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'misunderstood' => 'integer',
            'last_customer_at' => 'datetime',
            'last_store_at' => 'datetime',
            'human_until' => 'datetime',
            'waiting_since' => 'datetime',
            'cart_touched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<WhatsAppBotMessage, $this> */
    public function botMessages(): HasMany
    {
        return $this->hasMany(WhatsAppBotMessage::class, 'conversation_id');
    }

    /**
     * Cliente pediu atendente (0), a pausa ainda vale e a loja não respondeu depois do pedido.
     *
     * @param  Builder<WhatsAppConversation>  $query
     */
    #[Scope]
    protected function waitingForAttendant(Builder $query): void
    {
        $query->whereNotNull('waiting_since')
            ->where('human_until', '>', now())
            ->where(fn (Builder $inner) => $inner->whereNull('last_store_at')->orWhereColumn('last_store_at', '<', 'waiting_since'));
    }

    public function isWaitingForAttendant(): bool
    {
        return $this->waiting_since !== null
            && $this->human_until?->isFuture()
            && ($this->last_store_at === null || $this->last_store_at->lt($this->waiting_since));
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isWaitingForAttendant() => 'Aguardando atendente',
            (bool) $this->human_until?->isFuture() => 'Com atendente',
            $this->state === 'done' => 'Pedido feito',
            default => 'No roteiro',
        };
    }
}
