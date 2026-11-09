<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use App\Support\EvolutionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConnectWhatsApp
{
    public function __construct(private EvolutionClient $evolution) {}

    /** Cria a instância da loja, se faltar, e guarda o QR para a tela exibir. */
    public function handle(Company $company): void
    {
        if (! $this->evolution->configured()) {
            throw ValidationException::withMessages(['whatsapp' => 'O WhatsApp ainda não foi configurado no servidor.']);
        }

        $setting = WhatsAppSetting::where('company_id', $company->id)->first() ?? new WhatsAppSetting;

        try {
            $qr = null;
            $needsInstance = true;

            if (filled($setting->instance_name) && filled($setting->instance_token)) {
                try {
                    $qr = $this->evolution->connect($setting->instance_name, $setting->instance_token);
                    $needsInstance = false;
                } catch (EvolutionException $e) {
                    if (! in_array($e->status, [401, 404], true)) {
                        throw $e;
                    }
                }
            }

            if ($needsInstance) {
                $name = 'delivery-'.$company->id.'-'.Str::lower(Str::random(6));
                $created = $this->evolution->createInstance($name);
                $setting->forceFill([
                    'company_id' => $company->id,
                    'instance_name' => $name,
                    'instance_token' => $created['token'],
                ])->save();
                $qr = $created['qr'];
            }
        } catch (EvolutionException) {
            throw ValidationException::withMessages(['whatsapp' => 'Não foi possível falar com o servidor do WhatsApp. Tente de novo em instantes.']);
        }

        if ($qr !== null) {
            Cache::put("whatsapp-qr:{$company->id}", $qr, 60);
        }
    }
}
