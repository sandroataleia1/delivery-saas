<?php

namespace Tests\Feature\Api\V1;

use App\Actions\OpenTable;
use App\Actions\SavePizzaSetup;
use App\Models\DiningTable;
use App\Models\Option;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AddItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $waiter;

    private TableOrder $order;

    private Product $soda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->waiter = $this->userWith(['tables.operate'], $this->productionAdminOf()->company);
        $table = DiningTable::factory()->create(['company_id' => $this->waiter->company_id, 'number' => 5]);
        $this->order = app(OpenTable::class)->handle($table, 2, $this->waiter);
        $this->soda = Product::factory()->create(['company_id' => $this->waiter->company_id, 'name' => 'Refrigerante', 'sale_price' => '10.00', 'track_stock' => false]);
        Sanctum::actingAs($this->waiter);
    }

    /** @param  array<int, array<string, mixed>>|null  $items */
    private function send(?array $items = null, ?string $uuid = null)
    {
        return $this->postJson("/api/v1/orders/{$this->order->id}/items", [
            'client_uuid' => $uuid ?? (string) Str::uuid(),
            'items' => $items ?? [['product_id' => $this->soda->id, 'quantity' => 2]],
        ]);
    }

    /** @return array{0: Product, 1: int, 2: Option} */
    private function pizza(): array
    {
        $pizza = Product::factory()->create(['company_id' => $this->waiter->company_id, 'name' => 'Pizza', 'sale_price' => '40', 'track_stock' => false]);
        app(SavePizzaSetup::class)->handle($pizza, [
            'pizza_pricing' => 'highest',
            'sizes' => [['name' => 'Grande', 'slices' => 8, 'max_flavors' => 2, 'active' => true, 'recipe' => []]],
            'flavors' => [['name' => 'Calabresa', 'active' => true, 'prices' => ['n0' => '49.90'], 'recipes' => []]],
        ]);

        return [$pizza, $pizza->pizzaSizes()->firstOrFail()->id, $pizza->optionGroups()->where('kind', 'flavor')->firstOrFail()->options->first()];
    }

    public function test_lanca_itens_com_o_preco_do_servidor(): void
    {
        $this->send()
            ->assertCreated()
            ->assertJsonPath('data.items.0.product_name', 'Refrigerante')
            ->assertJsonPath('data.items.0.total', '20.00')
            ->assertJsonPath('data.items.0.status', 'waiting')
            ->assertJsonPath('data.total', '20.00');

        $this->assertSame(1, ProductionWork::count());
    }

    public function test_reenvio_com_o_mesmo_uuid_nao_duplica(): void
    {
        $uuid = (string) Str::uuid();

        $this->send(uuid: $uuid)->assertCreated();
        $this->send(uuid: $uuid)->assertCreated()->assertJsonCount(1, 'data.items');

        $this->assertSame(1, $this->order->items()->count());
    }

    public function test_produto_esgotado_e_recusado(): void
    {
        $this->soda->forceFill(['sold_out' => true])->save();

        $this->send()
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Refrigerante está esgotado.');

        $this->assertSame(0, $this->order->items()->count());
    }

    public function test_reenvio_depois_de_esgotar_devolve_o_pedido_ja_lancado(): void
    {
        $uuid = (string) Str::uuid();
        $this->send(uuid: $uuid)->assertCreated();
        $this->soda->forceFill(['sold_out' => true])->save();

        $this->send(uuid: $uuid)->assertCreated()->assertJsonCount(1, 'data.items');
    }

    public function test_comanda_fechada_recusa(): void
    {
        $this->order->forceFill(['status' => TableOrder::CLOSED, 'closed_at' => now(), 'open_table_id' => null])->save();

        $this->send()
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta comanda já foi fechada.');
    }

    public function test_cotacao_de_pizza_devolve_preco_e_rotulo_sem_gravar(): void
    {
        [$pizza, $size, $flavor] = $this->pizza();

        $this->postJson("/api/v1/orders/{$this->order->id}/quote", [
            'product_id' => $pizza->id, 'quantity' => 1, 'size_id' => $size, 'flavor_ids' => [$flavor->id],
        ])
            ->assertOk()
            ->assertJsonPath('unit_price', '49.90')
            ->assertJsonPath('options_label', 'Grande · Calabresa');

        $this->assertSame(0, $this->order->items()->count());
    }

    public function test_cotacao_com_sabor_pausado_avisa(): void
    {
        [$pizza, $size, $flavor] = $this->pizza();
        $flavor->forceFill(['paused' => true])->save();

        $this->postJson("/api/v1/orders/{$this->order->id}/quote", [
            'product_id' => $pizza->id, 'quantity' => 1, 'size_id' => $size, 'flavor_ids' => [$flavor->id],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Calabresa'));
    }
}
