<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use App\Support\EvolutionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class DisconnectWhatsApp
{
    public function __construct(private EvolutionClient $evolution) {}

    /** Sai da sessão e apaga a instância: reconectar sempre cria uma instância nova, com webhook novo. */
    public function handle(Company $company): void
    {
        if (! $this->evolution->configured()) {
            throw ValidationException::withMessages(['whatsapp' => 'O WhatsApp ainda não foi configurado no servidor.']);
        }

        $setting = WhatsAppSetting::where('company_id', $company->id)->first();

        if ($setting !== null && filled($setting->instance_name) && filled($setting->instance_token)) {
            try {
                $this->evolution->logout($setting->instance_name, $setting->instance_token);
            } catch (EvolutionException $e) {
                // 400: a sessão já estava fechada. 401/404: a instância nem existe mais.
                if (! in_array($e->status, [400, 401, 404], true)) {
                    throw $this->unreachable();
                }
            }

            try {
                $this->evolution->deleteInstance($setting->instance_name);
            } catch (EvolutionException $e) {
                if ($e->status !== 404) {
                    throw $this->unreachable();
                }
            }

            $setting->forceFill([
                'instance_name' => null,
                'instance_token' => null,
                'webhook_secret' => null,
                'state' => 'close',
                'state_checked_at' => now(),
            ])->save();
        }

        Cache::forget("whatsapp-qr:{$company->id}");
    }

    private function unreachable(): ValidationException
    {
        return ValidationException::withMessages(['whatsapp' => 'Não foi possível falar com o servidor do WhatsApp. Tente de novo em instantes.']);
    }
}
