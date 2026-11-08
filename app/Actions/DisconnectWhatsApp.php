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

    public function handle(Company $company): void
    {
        if (! $this->evolution->configured()) {
            throw ValidationException::withMessages(['whatsapp' => 'O WhatsApp ainda não foi configurado no servidor.']);
        }

        $setting = WhatsAppSetting::where('company_id', $company->id)->first();

        if ($setting !== null && filled($setting->instance_name) && filled($setting->instance_token)) {
            try {
                $this->evolution->logout($setting->instance_name, $setting->instance_token);
            } catch (EvolutionException) {
                throw ValidationException::withMessages(['whatsapp' => 'Não foi possível falar com o servidor do WhatsApp. Tente de novo em instantes.']);
            }

            $setting->forceFill(['state' => 'close', 'state_checked_at' => now()])->save();
        }

        Cache::forget("whatsapp-qr:{$company->id}");
    }
}
