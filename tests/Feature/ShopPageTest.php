<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\DeliveryZone;
use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopPageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private OnlineStore $store;

    private Product $burger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['trade_name' => 'Lanchonete do Zé']);
        $this->store = OnlineStore::factory()->create(['company_id' => $this->company->id, 'enabled' => true, 'slug' => 'lanchonete', 'min_order' => '20.00', 'eta_text' => '40-50 min']);
        (new OnlineStoreHour)->forceFill(['company_id' => $this->company->id, 'weekday' => 6, 'opens_at' => '18:00:00', 'closes_at' => '23:00:00'])->save();
        $category = new Category(['name' => 'Lanches']);
        $category->company_id = $this->company->id;
        $category->save();
        $this->burger = Product::factory()->create([
            'company_id' => $this->company->id, 'category_id' => $category->id, 'name' => 'X-Bacon', 'description' => 'Pão, carne e bacon',
            'sale_price' => '28.00', 'cost_price' => '11.00', 'sku' => 'XB-01', 'barcode' => '7890000000001',
        ]);
        $zone = new DeliveryZone(['name' => 'Centro', 'fee' => '6.00']);
        $zone->company_id = $this->company->id;
        $zone->save();
        $this->at('2026-11-07 19:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function at(string $local): void
    {
        $moment = CarbonImmutable::parse($local, 'America/Sao_Paulo');
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    public function test_shop_page_shows_store_products_zones_and_payment_methods(): void
    {
        $this->get('/loja/lanchonete')->assertOk()->assertInertia(fn (Assert $page) => $page->component('shop/Show')
            ->where('store.name', 'Lanchonete do Zé')
            ->where('store.slug', 'lanchonete')
            ->where('store.open', true)
            ->where('store.label', 'Aberto')
            ->where('store.min_order', '20.00')
            ->where('store.eta_text', '40-50 min')
            ->has('categories', 1)
            ->has('products', 1)
            ->where('products.0.name', 'X-Bacon')
            ->where('products.0.sale_price', '28.00')
            ->where('zones.0.name', 'Centro')
            ->where('zones.0.fee', '6.00')
            ->where('payment_methods', ['cash' => 'Dinheiro', 'pix' => 'Pix', 'credit' => 'Cartão de crédito', 'debit' => 'Cartão de débito']));
    }

    public function test_shop_page_exposes_no_sku_cost_or_barcode(): void
    {
        $group = new OptionGroup(['name' => 'Adicionais', 'kind' => 'extra', 'min_choices' => 0, 'max_choices' => 2, 'allows_quantity' => false, 'active' => true]);
        $group->company_id = $this->company->id;
        $group->save();
        $group->products()->attach($this->burger->id, ['company_id' => $this->company->id, 'position' => 0]);
        $option = new Option(['name' => 'Ovo', 'price' => '3.00', 'active' => true, 'position' => 0]);
        $option->company_id = $this->company->id;
        $option->option_group_id = $group->id;
        $option->save();

        $response = $this->get('/loja/lanchonete')->assertOk();
        $props = $response->viewData('page')['props'];
        $json = json_encode([$props['products'], $props['composition']]);

        $this->assertArrayHasKey($this->burger->id, $props['composition']);
        foreach (['sku', 'barcode', 'cost_price', 'recipe', 'stock_quantity', 'XB-01', '7890000000001'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(['id', 'name', 'description', 'sale_price', 'sold_out', 'category_id', 'image_url'], array_keys($props['products'][0]));
    }

    public function test_disabled_or_unknown_slug_404(): void
    {
        $this->get('/loja/nao-existe')->assertNotFound();

        $this->store->forceFill(['enabled' => false])->save();
        $this->get('/loja/lanchonete')->assertNotFound();

        $this->store->forceFill(['enabled' => true])->save();
        $this->company->forceFill(['active' => false])->save();
        $this->get('/loja/lanchonete')->assertNotFound();
    }

    public function test_shop_page_ignores_logged_in_staff_of_other_company(): void
    {
        $outsider = $this->adminOf();
        Product::factory()->create(['company_id' => $outsider->company_id, 'name' => 'Produto alheio']);

        $this->actingAs($outsider)->get('/loja/lanchonete')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('products', 1)
            ->where('products.0.name', 'X-Bacon')
            ->where('store.name', 'Lanchonete do Zé'));
    }

    public function test_closed_label_shown(): void
    {
        $this->at('2026-11-07 10:00');

        $this->get('/loja/lanchonete')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('store.open', false)
            ->where('store.label', 'Fechado · abre hoje às 18h'));
    }

    public function test_inactive_unsellable_and_other_company_products_are_left_out_sold_out_kept(): void
    {
        Product::factory()->create(['company_id' => $this->company->id, 'active' => false]);
        $flour = Product::factory()->create(['company_id' => $this->company->id]);
        $flour->forceFill(['sellable' => false])->save();
        Product::factory()->create(['company_id' => $this->company->id, 'name' => 'Coca', 'sold_out' => true]);
        Product::factory()->create();

        $this->get('/loja/lanchonete')->assertInertia(fn (Assert $page) => $page
            ->has('products', 2)
            ->where('products.0.name', 'Coca')
            ->where('products.0.sold_out', true));
    }
}
