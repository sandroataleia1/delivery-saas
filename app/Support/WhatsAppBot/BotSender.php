<?php

namespace App\Support\WhatsAppBot;

use App\Models\WhatsAppBotMessage;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use App\Support\EvolutionException;

/**
 * Envia as respostas do bot. Grava cada texto antes de enviar, para o eco (fromMe) nunca parecer um atendente.
 */
class BotSender
{
    public function __construct(private EvolutionClient $evolution) {}

    public function send(WhatsAppSetting $setting, WhatsAppConversation $conversation, string $text): void
    {
        $record = WhatsAppBotMessage::create([
            'company_id' => $conversation->company_id,
            'conversation_id' => $conversation->id,
            'body' => $text,
            'created_at' => now(),
        ]);

        try {
            $id = $this->evolution->sendText(
                $setting->instance_name,
                (string) $setting->instance_token,
                $conversation->phone !== null ? '55'.$conversation->phone : $conversation->remote_jid,
                $text,
            );
            $record->forceFill(['wa_message_id' => $id])->save();
        } catch (EvolutionException $e) {
            report($e);
        }
    }
}
