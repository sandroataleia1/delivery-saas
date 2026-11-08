<?php

namespace App\Console\Commands;

use App\Jobs\SendWhatsAppMessage;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:sweep')]
#[Description('Reenfileira mensagens de WhatsApp paradas e encerra as presas em envio')]
class SweepWhatsAppMessagesCommand extends Command
{
    public const STUCK_ERROR = 'Envio interrompido; não reenviado para evitar mensagem duplicada.';

    public function handle(): int
    {
        $redispatched = 0;
        WhatsAppMessage::withoutGlobalScopes()
            ->where('status', WhatsAppMessage::PENDING)
            ->where('attempts', '<', WhatsAppMessage::MAX_ATTEMPTS)
            ->where('updated_at', '<', now()->subMinutes(35))
            ->pluck('id')
            ->each(function (int $id) use (&$redispatched): void {
                SendWhatsAppMessage::dispatch($id);
                $redispatched++;
            });

        $failed = WhatsAppMessage::withoutGlobalScopes()
            ->where('status', WhatsAppMessage::SENDING)
            ->where('updated_at', '<', now()->subMinutes(15))
            ->update(['status' => WhatsAppMessage::FAILED, 'last_error' => self::STUCK_ERROR, 'updated_at' => now()]);

        $this->info("Mensagens reenfileiradas: {$redispatched}");
        $this->info("Mensagens presas marcadas como falha: {$failed}");

        return self::SUCCESS;
    }
}
