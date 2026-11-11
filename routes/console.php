<?php

use App\Models\WaiterNotice;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('online-orders:expire')->everyMinute()->withoutOverlapping();

Schedule::command('whatsapp:sweep')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('model:prune', ['--model' => [WhatsAppInboundMessage::class, WaiterNotice::class]])->daily();
