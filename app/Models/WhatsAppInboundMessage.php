<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Mensagem crua recebida pelo webhook da Evolution. Sem escopo de empresa: é gravada e lida fora de sessão.
 */
class WhatsAppInboundMessage extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $table = 'whatsapp_inbound_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'from_me' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(7));
    }
}
