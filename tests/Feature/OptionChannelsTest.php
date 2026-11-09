<?php

namespace Tests\Feature;

use App\Actions\DispatchProduction;
use App\Actions\OpenTable;
use App\Actions\RegisterSale;
use App\Actions\SavePizzaSetup;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\MenuOrderReceiptItem;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\TableOrderItem;
use App\Models\User;
use App\Support\ProductComposition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OptionChannelsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $burger;

    private Option $bacon;

    private Product $pizza;

    private int $grande;

    /** @var array<int, int> */
    private array $flavorIds = [];

    private function setUpCatalog(): void
    {
        $this->admin = $this->productionAdminOf();
        $this->actingAs($this->admin);
        $companyId = $this->admin->company_id;
        $this->burger = Product::factory()->create(['company_id' => $companyId, 'name' => 'X-Bacon', 'sale_price' => '28', 'track_stock' => false]);
        $group = new OptionGroup(['name' => 'Adicionais', 'kind' => 'extra', 'min_choices' => 0, 'max_choices' => 3, 'allows_quantity' => true, 'active' => true]);
        $group->company_id = $companyId;
        $group->save();
        $group->products()->attach($this->burger->id, ['company_id' => $companyId, 'position' => 0]);
        $this->bacon = new Option(['name' => 'Bacon', 'price' => '5.00', 'active' => true, 'position' => 0]);
        $this->bacon->company_id = $companyId;
        $this->bacon->option_group_id = $group->id;
        $this->bacon->save();

        $this->pizza = Product::factory()->create(['company_id' => $companyId, 'name' => 'Pizza', 'sale_price' => '40', 'track_stock' => false]);
        app(SavePizzaSetup::class)->handle($this->pizza, [
            'pizza_pricing' => 'highest',
            'sizes' => [
                ['name' => 'Broto', 'slices' => 4, 'max_flavors' => 1, 'active' => true, 'recipe' => []],
                ['name' => 'Grande', 'slices' => 8, 'max_flavors' => 2, 'active' => true, 'recipe' => []],
            ],
            'flavors' => [
                ['name' => 'Calabresa', 'active' => true, 'prices' => ['n0' => '39.90', 'n1' => '49.90'], 'recipes' => []],
                ['name' => 'Quatro queijos', 'active' => true, 'prices' => ['n0' => '44.90', 'n1' => '59.90'], 'recipes' => []],
            ],
        ]);
        $this->grande = $this->pizza->pizzaSizes()->orderBy('position')->get()->last()->id;
        $this->flavorIds = $this->pizza->optionGroups()->where('kind', 'flavor')->firstOrFail()->options->pluck('id')->all();
    }

    /** @return array<string, mixed> */
    private function halfAndHalf(): array
    {
        return ['product_id' => $this->pizza->id, 'quantity' => '1', 'size_id' => $this->grande, 'flavor_ids' => $this->flavorIds];
    }

    public function test_dispatch_copies_options_label_to_production_work(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        $snapshot = ['size' => ['id' => 1, 'name' => 'Grande'], 'flavors' => []];
        $item = new TableOrderItem;
        $item->forceFill(['company_id' => $admin->company_id, 'table_order_id' => $order->id, 'product_id' => $product->id,
            'client_uuid' => (string) Str::uuid(), 'user_name' => $admin->name, 'product_name' => $product->name, 'unit' => $product->unit, 'quantity' => '1.000', 'unit_price' => '10.00', 'total' => '10.00',
            'options' => $snapshot, 'options_label' => 'Grande · ½ A ½ B'])->save();

        $work = app(DispatchProduction::class)->handle($item);

        $this->assertSame('Grande · ½ A ½ B', $work->fresh()->options_label);
        $this->assertSame($snapshot, ProductionWork::sole()->options);
        $this->actingAs($admin)->get(route('production.index'))
            ->assertInertia(fn ($page) => $page->where('works.0.options_label', 'Grande · ½ A ½ B'));
    }

    public function test_pos_sells_same_product_twice_with_different_extras(): void
    {
        $this->setUpCatalog();
        $this->openCash($this->admin);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => $this->burger->id, 'quantity' => '1', 'expected_price' => '33.00', 'options' => [['option_id' => $this->bacon->id]]],
                ['product_id' => $this->burger->id, 'quantity' => '1', 'expected_price' => '28.00'],
            ],
            'payments' => [['method' => 'pix', 'amount' => '61.00']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('61.00', Sale::sole()->total);
        $items = SaleItem::orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['+1 Bacon', null], $items->pluck('options_label')->all());
        $withBacon = $items->firstWhere('options_label', '+1 Bacon');
        $this->assertSame('33.00', $withBacon->unit_price);
        $this->assertSame('Bacon', $withBacon->options['extras'][0]['name']);
        $this->assertNull($items->firstWhere('options_label', null)->options);
    }

    public function test_pos_rejects_stale_expected_price_with_options(): void
    {
        $this->setUpCatalog();
        $this->openCash($this->admin);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->burger->id, 'quantity' => '1', 'expected_price' => '28.00', 'options' => [['option_id' => $this->bacon->id]]]],
            'payments' => [['method' => 'pix', 'amount' => '33.00']],
        ])->assertSessionHasErrors(['items' => 'O preço de X-Bacon mudou para R$ 33,00. Confira o carrinho.']);

        $this->assertSame(0, Sale::count());
    }

    public function test_pos_reports_composition_error_under_original_index(): void
    {
        $this->setUpCatalog();
        $this->openCash($this->admin);
        $other = Product::factory()->create(['company_id' => $this->admin->company_id, 'sale_price' => '5']);
        $lowerId = min($other->id, $this->burger->id);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [
                ['product_id' => max($other->id, $this->burger->id), 'quantity' => '1', 'expected_price' => '28.00', 'options' => [['option_id' => 999999]]],
                ['product_id' => $lowerId, 'quantity' => '1', 'expected_price' => $lowerId === $other->id ? '5.00' : '28.00'],
            ],
            'payments' => [['method' => 'pix', 'amount' => '33.00']],
        ])->assertSessionHasErrors('items.0');
    }

    public function test_table_launch_stores_composed_price_and_snapshot(): void
    {
        $this->setUpCatalog();
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $this->admin->company_id]), 1, $this->admin);

        $this->post(route('orders.items', $order), ['client_uuid' => (string) Str::uuid(), 'items' => [$this->halfAndHalf()]])
            ->assertSessionHasNoErrors();

        $item = TableOrderItem::sole();
        $this->assertSame('59.90', $item->unit_price);
        $this->assertCount(2, $item->options['flavors']);
        $this->assertSame('Grande · ½ Calabresa ½ Quatro queijos', $item->options_label);
        $this->assertSame($item->options_label, ProductionWork::sole()->options_label);
    }

    public function test_staff_delivery_stores_composed_lines(): void
    {
        $this->setUpCatalog();
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);

        $this->post(route('deliveries.store'), [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'type' => 'pickup',
            'items' => [$this->halfAndHalf()],
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $item = DeliveryOrder::sole()->items()->sole();
        $this->assertSame('59.90', $item->unit_price);
        $this->assertCount(2, $item->options['flavors']);
        $this->assertSame('Grande · ½ Calabresa ½ Quatro queijos', $item->options_label);
        $this->assertSame($item->options_label, ProductionWork::sole()->options_label);
    }

    public function test_replay_after_option_paused_returns_persisted_launch(): void
    {
        $this->setUpCatalog();
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $this->admin->company_id]), 1, $this->admin);
        $payload = ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->burger->id, 'quantity' => '1', 'options' => [['option_id' => $this->bacon->id]]]]];

        $this->post(route('orders.items', $order), $payload)->assertSessionHasNoErrors();
        $this->bacon->forceFill(['paused' => true])->save();
        $this->post(route('orders.items', $order), $payload)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, TableOrderItem::count());
    }

    public function test_register_sale_persists_options_snapshot_with_fixed_price(): void
    {
        $this->setUpCatalog();
        $this->openCash($this->admin);
        $snapshot = ['size' => null, 'extras' => [['name' => 'Bacon']]];

        [$sale] = app(RegisterSale::class)->handle([
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->burger->id, 'quantity' => '1', 'fixed_price' => '33.00', 'options_snapshot' => $snapshot, 'options_label' => '+1 Bacon']],
            'payments' => [['method' => 'pix', 'amount' => '33.00']],
        ]);

        $line = $sale->items()->sole();
        $this->assertSame('33.00', $line->unit_price);
        $this->assertSame($snapshot, $line->options);
        $this->assertSame('+1 Bacon', $line->options_label);
    }

    public function test_product_composition_lists_only_composable_products_and_hides_inactive_options(): void
    {
        $this->setUpCatalog();
        $plain = Product::factory()->create(['company_id' => $this->admin->company_id]);
        $this->bacon->forceFill(['paused' => true])->save();
        $hidden = new Option(['name' => 'Oculta', 'price' => '1.00', 'active' => false, 'position' => 1]);
        $hidden->company_id = $this->admin->company_id;
        $hidden->option_group_id = $this->bacon->option_group_id;
        $hidden->save();

        $composition = ProductComposition::forProducts(collect([$plain, $this->burger, $this->pizza]));

        $this->assertSame([$this->burger->id, $this->pizza->id], array_keys($composition));
        $options = $composition[$this->burger->id]['groups'][0]['options'];
        $this->assertCount(1, $options);
        $this->assertTrue($options[0]['paused']);
        $this->assertNull($composition[$this->burger->id]['pizza']);
        $this->assertSame('highest', $composition[$this->pizza->id]['pizza']['pricing']);
        $this->assertCount(2, $composition[$this->pizza->id]['pizza']['sizes']);
        $this->assertSame('49.90', $composition[$this->pizza->id]['pizza']['flavors'][0]['prices'][$this->grande]);
    }

    public function test_group_of_a_category_applies_to_its_products_including_ones_created_later(): void
    {
        $this->setUpCatalog();
        $this->openCash($this->admin);
        $companyId = $this->admin->company_id;
        $snacks = Category::factory()->create(['company_id' => $companyId]);
        $group = OptionGroup::findOrFail($this->bacon->option_group_id);
        $group->products()->detach();
        $group->categories()->attach($snacks->id, ['company_id' => $companyId]);

        $later = Product::factory()->create(['company_id' => $companyId, 'name' => 'X-Salada', 'sale_price' => '25', 'track_stock' => false, 'category_id' => $snacks->id]);
        $outside = Product::factory()->create(['company_id' => $companyId, 'name' => 'Suco', 'sale_price' => '8', 'track_stock' => false]);

        $composition = ProductComposition::forProducts(collect([$later, $outside]));
        $this->assertSame([$later->id], array_keys($composition));
        $this->assertSame('Adicionais', $composition[$later->id]['groups'][0]['name']);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $later->id, 'quantity' => '1', 'expected_price' => '30.00', 'options' => [['option_id' => $this->bacon->id]]]],
            'payments' => [['method' => 'pix', 'amount' => '30.00']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('+1 Bacon', SaleItem::sole()->options_label);

        $this->post('/vendas', [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $outside->id, 'quantity' => '1', 'expected_price' => '13.00', 'options' => [['option_id' => $this->bacon->id]]]],
            'payments' => [['method' => 'pix', 'amount' => '13.00']],
        ])->assertSessionHasErrors('items.0');
    }

    public function test_group_linked_to_product_and_its_category_appears_once(): void
    {
        $this->setUpCatalog();
        $companyId = $this->admin->company_id;
        $snacks = Category::factory()->create(['company_id' => $companyId]);
        $this->burger->update(['category_id' => $snacks->id]);
        OptionGroup::findOrFail($this->bacon->option_group_id)->categories()->attach($snacks->id, ['company_id' => $companyId]);

        $composition = ProductComposition::forProducts(collect([$this->burger]));

        $this->assertCount(1, $composition[$this->burger->id]['groups']);
    }

    /** @return array{0: DiningTable, 1: array<string, mixed>} */
    private function qrPayload(array $items): array
    {
        $this->admin->company->forceFill(['qr_menu_enabled' => true])->save();
        $table = DiningTable::factory()->create(['company_id' => $this->admin->company_id]);

        return [$table, ['service_context' => $table->service_context, 'client_uuid' => (string) Str::uuid(),
            'tracking_key' => str_repeat('a', 64), 'items' => $items]];
    }

    public function test_qr_receipt_with_two_compositions_of_same_product(): void
    {
        $this->setUpCatalog();
        [$table, $data] = $this->qrPayload([$this->halfAndHalf(), [...$this->halfAndHalf(), 'flavor_ids' => [$this->flavorIds[0]]]]);

        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated()->assertJsonPath('receipt.total', '109.80');

        $items = MenuOrderReceiptItem::orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame(['59.90', '49.90'], $items->pluck('unit_price')->all());
        $this->assertNotSame($items[0]->options_label, $items[1]->options_label);
    }

    public function test_confirm_refuses_when_option_paused_after_receipt(): void
    {
        $this->setUpCatalog();
        [$table, $data] = $this->qrPayload([['product_id' => $this->burger->id, 'quantity' => 1, 'options' => [['option_id' => $this->bacon->id]]]]);
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $this->bacon->forceFill(['paused' => true])->save();

        $this->actingAs($this->admin)->postJson(route('receipts.confirm', MenuOrderReceipt::sole()->id))->assertUnprocessable()
            ->assertJsonValidationErrors(['receipt' => 'A opção Bacon ficou indisponível. Resolva ou rejeite o pedido.']);

        $this->assertSame('pending', MenuOrderReceipt::sole()->status);
        $this->assertDatabaseCount('table_order_items', 0);
    }

    public function test_confirm_copies_snapshot_to_table_item(): void
    {
        $this->setUpCatalog();
        [$table, $data] = $this->qrPayload([['product_id' => $this->burger->id, 'quantity' => 2, 'options' => [['option_id' => $this->bacon->id]]]]);
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();

        $this->actingAs($this->admin)->postJson(route('receipts.confirm', MenuOrderReceipt::sole()->id))->assertOk();

        $received = MenuOrderReceiptItem::sole();
        $item = TableOrderItem::sole();
        $this->assertSame('33.00', $item->unit_price);
        $this->assertSame('66.00', $item->total);
        $this->assertSame('+1 Bacon', $item->options_label);
        $this->assertSame($received->options, $item->options);
    }

    public function test_qr_replay_with_different_options_is_conflict(): void
    {
        $this->setUpCatalog();
        [$table, $data] = $this->qrPayload([$this->halfAndHalf()]);
        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();

        $this->postJson('/m/'.$table->qr_token, $data)->assertOk();
        $changed = $data;
        $changed['items'][0]['flavor_ids'] = [$this->flavorIds[0]];
        $this->postJson('/m/'.$table->qr_token, $changed)->assertStatus(409);
        $this->assertDatabaseCount('menu_order_receipts', 1);
    }

    public function test_composition_from_price_ignores_sale_price_for_pizza_and_adds_required_groups(): void
    {
        $this->setUpCatalog();
        $this->pizza->forceFill(['sale_price' => '0'])->save();
        $cheapest = Option::find($this->flavorIds[0]);
        $cheapest->forceFill(['paused' => true])->save();

        $required = new OptionGroup(['name' => 'Molho', 'kind' => 'extra', 'min_choices' => 1, 'max_choices' => 2, 'allows_quantity' => false, 'active' => true]);
        $required->company_id = $this->admin->company_id;
        $required->save();
        $required->products()->attach($this->burger->id, ['company_id' => $this->admin->company_id, 'position' => 1]);
        foreach (['3.00', '2.00', '9.00'] as $position => $price) {
            $option = new Option(['name' => 'Molho '.$price, 'price' => $price, 'active' => true, 'position' => $position]);
            $option->company_id = $this->admin->company_id;
            $option->option_group_id = $required->id;
            $option->save();
        }

        $composition = ProductComposition::forProducts(collect([$this->burger, $this->pizza]));

        $this->assertSame('44.90', $composition[$this->pizza->id]['from_price']);
        $this->assertSame('30.00', $composition[$this->burger->id]['from_price']);
    }

    public function test_qr_labels_follow_the_guests_flavor_order_and_replay_with_reordered_flavors_is_not_conflict(): void
    {
        $this->setUpCatalog();
        [$higher, $lower] = [max($this->flavorIds), min($this->flavorIds)];
        $names = Option::whereIn('id', [$higher, $lower])->pluck('name', 'id');
        [$table, $data] = $this->qrPayload([[...$this->halfAndHalf(), 'flavor_ids' => [$higher, $lower]]]);

        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();

        $label = MenuOrderReceiptItem::sole()->options_label;
        $this->assertLessThan(strpos($label, $names[$lower]), strpos($label, $names[$higher]));

        $reordered = $data;
        $reordered['items'][0]['flavor_ids'] = [$lower, $higher];
        $this->postJson('/m/'.$table->qr_token, $reordered)->assertOk();
        $this->assertDatabaseCount('menu_order_receipts', 1);
    }
}
