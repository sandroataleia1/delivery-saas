<?php

namespace App\Jobs;

use App\Models\WhatsAppInboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Processa uma mensagem recebida pelo webhook do WhatsApp.
 */
class ProcessWhatsAppInbound implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $inboundId) {}

    public function handle(): void
    {
        $inbound = WhatsAppInboundMessage::find($this->inboundId);

        if ($inbound === null || $inbound->processed_at !== null) {
            return;
        }

        $inbound->forceFill(['processed_at' => now()])->save();
    }
}
