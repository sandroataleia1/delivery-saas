<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mensagem enviada pelo bot. Serve para reconhecer o eco (fromMe) e não confundir com o atendente.
 */
class WhatsAppBotMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'whatsapp_bot_messages';

    protected $guarded = ['id'];
}
