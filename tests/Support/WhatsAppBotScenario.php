<?php

namespace Tests\Support;

use App\Actions\SavePizzaSetup;
use App\Models\Category;
use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
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

    protected function category(string $name, ?Company $company = null): Category
    {
        return Category::factory()->create(['company_id' => ($company ?? $this->company)->id, 'name' => $name]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function product(string $name, string $price, ?Category $category, array $attributes = []): Product
    {
        return Product::factory()->create([
            'company_id' => $category?->company_id ?? $this->company->id, 'name' => $name, 'sale_price' => $price,
            'category_id' => $category?->id, 'track_stock' => false, ...$attributes,
        ]);
    }

    /**
     * Pizza "highest": Broto (1 sabor) e Grande (até 2), sabores Calabresa e Quatro queijos, e Portuguesa pausada.
     *
     * @return array{pizza: Product, broto: int, grande: int}
     */
    protected function pizza(Category $category): array
    {
        $pizza = $this->product('Pizza', '0', $category);
        app(SavePizzaSetup::class)->handle($pizza, [
            'pizza_pricing' => 'highest',
            'sizes' => [
                ['name' => 'Broto', 'slices' => 4, 'max_flavors' => 1, 'active' => true, 'recipe' => []],
                ['name' => 'Grande', 'slices' => 8, 'max_flavors' => 2, 'active' => true, 'recipe' => []],
            ],
            'flavors' => [
                ['name' => 'Calabresa', 'active' => true, 'prices' => ['n0' => '39.90', 'n1' => '49.90'], 'recipes' => []],
                ['name' => 'Quatro queijos', 'active' => true, 'prices' => ['n0' => '44.90', 'n1' => '59.90'], 'recipes' => []],
                ['name' => 'Portuguesa', 'active' => true, 'prices' => ['n0' => '41.90', 'n1' => '55.90'], 'recipes' => []],
            ],
        ]);
        Option::where('company_id', $pizza->company_id)->where('name', 'Portuguesa')->update(['paused' => true]);
        $sizes = $pizza->pizzaSizes()->orderBy('position')->pluck('id');

        return ['pizza' => $pizza, 'broto' => $sizes[0], 'grande' => $sizes[1]];
    }

    protected function optionGroup(Product $product, string $name, int $min, int $max, bool $quantity = false): OptionGroup
    {
        $group = new OptionGroup(['name' => $name, 'kind' => 'extra', 'min_choices' => $min, 'max_choices' => $max, 'allows_quantity' => $quantity, 'active' => true]);
        $group->company_id = $product->company_id;
        $group->save();
        $group->products()->attach($product->id, ['company_id' => $product->company_id, 'position' => $product->optionGroups()->count()]);

        return $group;
    }

    protected function option(OptionGroup $group, string $name, string $price, bool $paused = false): Option
    {
        $option = new Option(['name' => $name, 'price' => $price, 'active' => true, 'position' => $group->options()->count()]);
        $option->company_id = $group->company_id;
        $option->option_group_id = $group->id;
        $option->paused = $paused;
        $option->save();

        return $option;
    }

    /** @param  list<string>  $answers */
    protected function customerAnswers(array $answers): void
    {
        foreach ($answers as $answer) {
            $this->customerSays($answer);
        }
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
