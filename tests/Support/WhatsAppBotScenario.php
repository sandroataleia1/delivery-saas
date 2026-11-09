<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppSetting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Monta uma loja aberta com WhatsApp e bot ligados e conversa com ela pelo webhook, usando os payloads reais.
 * Todas as mensagens enviadas pelo bot ficam em sentTexts().
 */
trait WhatsAppBotScenario
{
    protected Company $company;

    protected OnlineStore $store;

    protected WhatsAppSetting $setting;

    private int $outgoing = 0;

    protected function setUpBot(): void
    {
        Http::preventStrayRequests();
        config(['services.evolution.url' => 'https://evo.test', 'services.evolution.key' => 'KEY']);
        Http::fake(['evo.test/message/sendText/*' => fn () => Http::response(['key' => ['id' => 'BOT'.(++$this->outgoing)]])]);

        [$this->company, $this->store, $this->setting] = $this->botStore('Pizzaria Bella', 'bella', 'inst-a');
        $this->at('2026-11-07 19:00');
    }

    /** @return array{Company, OnlineStore, WhatsAppSetting} */
    protected function botStore(string $name, string $slug, string $instance): array
    {
        $company = Company::factory()->create(['trade_name' => $name]);
        $store = OnlineStore::factory()->create(['company_id' => $company->id, 'enabled' => true, 'slug' => $slug]);
        (new OnlineStoreHour)->forceFill(['company_id' => $company->id, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $setting = WhatsAppSetting::factory()->create([
            'company_id' => $company->id, 'instance_name' => $instance, 'webhook_secret' => 'segredo-'.$instance,
            'enabled' => true, 'auto_reply' => true,
        ]);

        return [$company, $store, $setting];
    }

    protected function at(string $local): void
    {
        $moment = CarbonImmutable::parse($local, 'America/Sao_Paulo');
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    protected function later(int $minutes): void
    {
        $this->at(CarbonImmutable::now()->addMinutes($minutes)->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }

    /**
     * @param  array<string, mixed>  $key
     * @return array<string, mixed>
     */
    protected function fixture(string $name, ?string $text = null, array $key = [], string $instance = 'inst-a'): array
    {
        $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/whatsapp/{$name}.json")), true);
        $payload['instance'] = $instance;
        $payload['data']['key'] = [...$payload['data']['key'], 'id' => 'WA'.Str::upper(Str::random(20)), ...$key];

        if ($text !== null) {
            $payload['data']['message'] = ['conversation' => $text];
        }

        return $payload;
    }

    /** @param  array<string, mixed>  $payload */
    protected function deliver(array $payload, ?WhatsAppSetting $setting = null): void
    {
        $setting ??= $this->setting;
        $this->postJson("/webhooks/whatsapp/{$setting->instance_name}/{$setting->webhook_secret}", $payload)->assertOk();
    }

    protected function customerSays(string $text, ?WhatsAppSetting $setting = null): void
    {
        $setting ??= $this->setting;
        $this->deliver($this->fixture('customer_text', $text, instance: $setting->instance_name), $setting);
    }

    protected function storeSays(string $text): void
    {
        $this->deliver($this->fixture('store_reply_from_phone', $text));
    }

    /** @return list<string> */
    protected function sentTexts(?string $instance = null): array
    {
        return Http::recorded()
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/message/sendText/'.($instance ?? '')))
            ->map(fn (array $pair) => (string) $pair[0]['text'])
            ->values()
            ->all();
    }

    protected function lastSent(): ?string
    {
        $texts = $this->sentTexts();

        return $texts === [] ? null : $texts[array_key_last($texts)];
    }

    protected function conversation(?Company $company = null): WhatsAppConversation
    {
        return WhatsAppConversation::where('company_id', ($company ?? $this->company)->id)->firstOrFail();
    }

    protected function assertSentTo(string $number): void
    {
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/message/sendText/') && $request['number'] === $number);
    }
}
