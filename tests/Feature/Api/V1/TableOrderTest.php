<?php

namespace Tests\Feature\Api\V1;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->waiter = $this->userWith(['tables.operate'], $this->productionAdminOf()->company);
        Sanctum::actingAs($this->waiter);
    }

    private function table(int $number): DiningTable
    {
        return DiningTable::factory()->create(['company_id' => $this->waiter->company_id, 'number' => $number]);
    }

    private function openOrder(int $number = 5): TableOrder
    {
        return app(OpenTable::class)->handle($this->table($number), 2, $this->waiter);
    }

    private function addItem(TableOrder $order, string $name): TableOrderItem
    {
        $product = Product::factory()->create(['company_id' => $this->waiter->company_id, 'name' => $name, 'track_stock' => false]);
        $uuid = (string) Str::uuid();
        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], $uuid);

        return TableOrderItem::where('client_uuid', $uuid)->firstOrFail();
    }

    public function test_abre_mesa_livre_e_fica_como_responsavel(): void
    {
        $table = $this->table(5);

        $this->postJson("/api/v1/tables/{$table->id}/open", ['people' => 3])
            ->assertCreated()
            ->assertJsonPath('data.table', 5)
            ->assertJsonPath('data.people', 3)
            ->assertJsonPath('data.waiter', $this->waiter->name)
            ->assertJsonPath('data.items', []);

        $this->assertSame($this->waiter->id, TableOrder::firstOrFail()->user_id);
    }

    public function test_abrir_mesa_ocupada_avisa(): void
    {
        $table = $this->table(5);
        $this->postJson("/api/v1/tables/{$table->id}/open", ['people' => 2])->assertCreated();

        $this->postJson("/api/v1/tables/{$table->id}/open", ['people' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'A mesa 5 já está ocupada.');
    }

    public function test_comanda_mostra_itens_com_a_situacao(): void
    {
        $order = $this->openOrder();
        $waiting = $this->addItem($order, 'Esperando');
        $ready = $this->addItem($order, 'Pronto');
        $delivered = $this->addItem($order, 'Entregue');
        $cancelled = $this->addItem($order, 'Cancelado');
        $this->addItem($order, 'Por último?');
        ProductionWork::where('table_order_item_id', $ready->id)->update(['status' => 'ready']);
        ProductionWork::where('table_order_item_id', $delivered->id)->update(['status' => 'ready', 'delivered_at' => now()]);
        $cancelled->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Teste'])->save();

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.table', 5)
            ->assertJsonPath('data.total', '40.00')
            ->assertJsonPath('data.items.0.id', $waiting->id)
            ->assertJsonPath('data.items.0.status', 'waiting')
            ->assertJsonPath('data.items.0.product_name', 'Esperando')
            ->assertJsonPath('data.items.0.total', '10.00')
            ->assertJsonPath('data.items.1.status', 'ready')
            ->assertJsonPath('data.items.2.status', 'delivered')
            ->assertJsonPath('data.items.3.product_name', 'Por último?')
            ->assertJsonPath('data.items.4.id', $cancelled->id)
            ->assertJsonPath('data.items.4.status', 'cancelled');
    }

    public function test_comanda_de_outra_empresa_nao_aparece(): void
    {
        $other = $this->productionAdminOf();
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $other->company_id]), 2, $other);

        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
    }

    public function test_comanda_fechada_avisa(): void
    {
        $order = $this->openOrder();
        $order->forceFill(['status' => TableOrder::CLOSED, 'closed_at' => now(), 'open_table_id' => null])->save();

        $this->getJson("/api/v1/orders/{$order->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Esta comanda já foi fechada.');
    }
}
