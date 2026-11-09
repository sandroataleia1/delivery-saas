<?php

namespace App\Console\Commands;

use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use App\Support\EvolutionException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('whatsapp:register-webhooks')]
#[Description('Aponta o webhook de todas as instâncias do WhatsApp para o sistema')]
class RegisterWhatsAppWebhooksCommand extends Command
{
    public function handle(EvolutionClient $evolution): int
    {
        WhatsAppSetting::withoutGlobalScopes()
            ->whereNotNull('instance_name')
            ->whereNotNull('instance_token')
            ->orderBy('id')
            ->each(function (WhatsAppSetting $setting) use ($evolution): void {
                try {
                    $evolution->setWebhook($setting->instance_name, $setting->instance_token, $setting->webhookUrl());
                    $this->info("Webhook registrado: {$setting->instance_name}");
                } catch (EvolutionException $e) {
                    $this->warn("Falha em {$setting->instance_name}: {$e->getMessage()}");
                }
            });

        return self::SUCCESS;
    }
}
