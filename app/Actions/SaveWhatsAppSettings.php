<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\WhatsAppSetting;
use App\Support\BrazilPhone;
use App\Support\WhatsAppBot\InboundMessage;

class SaveWhatsAppSettings
{
    /**
     * @param  array{enabled: bool, templates?: array<string, ?string>|null, auto_reply?: bool, greeting?: ?string, ignored_numbers?: list<string>|null}  $data
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
        ]);

        if (array_key_exists('auto_reply', $data)) {
            $setting->auto_reply = (bool) $data['auto_reply'];
        }

        if (array_key_exists('greeting', $data)) {
            $setting->greeting = trim((string) $data['greeting']) ?: null;
        }

        if (array_key_exists('ignored_numbers', $data)) {
            $setting->ignored_numbers = collect($data['ignored_numbers'] ?? [])
                ->map(fn (string $number) => InboundMessage::withMobileNine(BrazilPhone::normalize($number)))
                ->unique()
                ->values()
                ->all();
        }

        $setting->save();

        return $setting;
    }
}
