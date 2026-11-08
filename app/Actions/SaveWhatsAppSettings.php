<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\WhatsAppSetting;

class SaveWhatsAppSettings
{
    /**
     * @param  array{enabled: bool, templates?: array<string, ?string>|null}  $data
     */
    public function handle(Company $company, array $data): WhatsAppSetting
    {
        $overrides = collect($data['templates'] ?? [])
            ->map(fn (?string $text) => trim((string) $text))
            ->filter(fn (string $text) => $text !== '')
            ->all();

        $setting = WhatsAppSetting::where('company_id', $company->id)->first() ?? new WhatsAppSetting;
        $setting->forceFill([
            'company_id' => $company->id,
            'enabled' => (bool) $data['enabled'],
            'templates' => $overrides,
        ])->save();

        return $setting;
    }
}
