<?php

namespace App\Jobs;

use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppSetting;
use App\Support\WhatsAppBot\InboundMessage;
use App\Support\WhatsAppBot\WhatsAppBot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Processa uma mensagem recebida pelo webhook do WhatsApp. Uma conversa por vez: mensagens seguidas
 * do mesmo contato esperam a anterior terminar, para o estado nunca ser lido duas vezes antes de gravado.
 */
class ProcessWhatsAppInbound implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public function __construct(public int $inboundId) {}

    public function handle(WhatsAppBot $bot): void
    {
        $inbound = WhatsAppInboundMessage::find($this->inboundId);

        if ($inbound === null || $inbound->processed_at !== null) {
            return;
        }

        $message = InboundMessage::fromPayload($inbound->payload);
        $setting = WhatsAppSetting::withoutGlobalScopes()->where('company_id', $inbound->company_id)->first();

        if ($message !== null && $setting !== null) {
            $lock = Cache::lock("whatsapp-conv:{$inbound->company_id}:{$message->remoteJid}", 30);

            if (! $lock->get()) {
                $this->release(3);

                return;
            }

            try {
                $bot->handle($setting, $message);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $lock->release();
            }
        }

        $inbound->forceFill(['processed_at' => now()])->save();
    }
}
