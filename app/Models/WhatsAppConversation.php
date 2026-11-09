<?php

namespace App\Models;

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
}
