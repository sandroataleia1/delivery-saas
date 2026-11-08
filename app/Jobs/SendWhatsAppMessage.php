<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $messageId) {}

    /** O envio pela Evolution entra na próxima etapa. */
    public function handle(): void
    {
        //
    }
}
