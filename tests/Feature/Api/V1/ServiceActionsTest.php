<?php

namespace Tests\Feature\Api\V1;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Actions\TransitionProduction;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $waiter;

    private DiningTable $table;

    private TableOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->productionAdminOf();
        $this->admin->company->forceFill(['qr_menu_enabled' => true])->save();
        $this->waiter = $this->userWith(['tables.operate', 'tables.confirm', 'production.deliver'], $this->admin->company);
        $this->table = DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 5]);
        $this->actingAs($this->admin);
        $this->order = app(OpenTable::class)->handle($this->table, 2, $this->admin);
        $burger = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'X-Burger']);
        $soda = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Refri']);
        app(AddOrderItems::class)->handle($this->order, [
            ['product_id' => $burger->id, 'quantity' => '1'],
            ['product_id' => $soda->id, 'quantity' => '1'],
        ], (string) Str::uuid());
    }

    private function work(string $product): ProductionWork
    {
        return ProductionWork::where('product_name', $product)->sole();
    }

    /** A cozinha marca o item como pronto. */
    private function makeReady(string $product): void
    {
        $this->actingAs($this->admin);
        $work = $this->work($product);
        app(TransitionProduction::class)->handle($work, $work->version, 'preparing');
        app(TransitionProduction::class)->handle($work, $work->version + 1, 'ready');
    }

    private function itemId(string $product): int
    {
        return $this->work($product)->table_order_item_id;
    }

    private function qrReceipt(): MenuOrderReceipt
    {
        $product = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Suco', 'sale_price' => '8']);
        $this->postJson('/m/'.$this->table->qr_token, ['service_context' => $this->table->fresh()->service_context, 'client_uuid' => (string) Str::uuid(),
            'tracking_key' => str_repeat('a', 64), 'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Sem gelo']]])->assertCreated();

        return MenuOrderReceipt::sole();
    }

    public function test_pedir_conta_marca_e_desmarca(): void
    {
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.bill', $this->order), ['requested' => true])->assertOk()->assertJsonPath('data.closing', true);
        $this->assertNotNull($this->order->fresh()->bill_requested_at);

        $this->postJson(route('api.v1.orders.bill', $this->order), ['requested' => false])->assertOk()->assertJsonPath('data.closing', false);
        $this->assertNull($this->order->fresh()->bill_requested_at);
    }

    public function test_pedir_conta_em_comanda_fechada_recusa(): void
    {
        $this->order->forceFill(['status' => 'closed', 'open_table_id' => null])->saveQuietly();
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.bill', $this->order), ['requested' => true])
            ->assertUnprocessable()->assertJsonPath('message', 'Esta comanda já foi fechada.');
    }

    public function test_trocar_de_mesa_passa_o_responsavel_para_quem_trocou(): void
    {
        $free = DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 9]);
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.transfer', $this->order), ['dining_table_id' => $free->id])->assertOk()
            ->assertJsonPath('data.table', 9)->assertJsonPath('data.waiter', $this->waiter->name);

        $this->assertSame($this->waiter->id, $this->order->fresh()->user_id);
    }

    public function test_trocar_para_mesa_ocupada_recusa(): void
    {
        $busy = DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 10]);
        app(OpenTable::class)->handle($busy, 1, $this->admin);
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.transfer', $this->order), ['dining_table_id' => $busy->id])
            ->assertUnprocessable()->assertJsonPath('errors.dining_table_id.0', 'A mesa 10 está ocupada.');
        $this->assertSame($this->table->id, $this->order->fresh()->dining_table_id);
    }

    public function test_entregar_item_pronto(): void
    {
        $this->makeReady('X-Burger');
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.deliver', $this->order), ['item_ids' => [$this->itemId('X-Burger')]])->assertOk()
            ->assertJsonPath('data.items.0.status', 'delivered')->assertJsonPath('data.items.0.deliverable', false);

        $this->assertNotNull($this->work('X-Burger')->delivered_at);
    }

    public function test_entregar_duas_vezes_nao_da_erro(): void
    {
        $this->makeReady('X-Burger');
        Sanctum::actingAs($this->waiter);
        $payload = ['item_ids' => [$this->itemId('X-Burger')]];

        $this->postJson(route('api.v1.orders.deliver', $this->order), $payload)->assertOk();
        $this->postJson(route('api.v1.orders.deliver', $this->order), $payload)->assertOk()->assertJsonPath('data.items.0.status', 'delivered');
    }

    public function test_outro_garcom_entregando_ao_mesmo_tempo_nao_da_erro(): void
    {
        $this->makeReady('X-Burger');
        Sanctum::actingAs($this->waiter);
        $payload = ['item_ids' => [$this->itemId('X-Burger')]];
        $raced = false;
        // Logo depois que a requisição lê o item, outro garçom o entrega.
        ProductionWork::retrieved(function (ProductionWork $work) use (&$raced) {
            if (! $raced && $work->status === 'ready' && $work->delivered_at === null) {
                $raced = true;
                ProductionWork::whereKey($work->id)->update(['delivered_at' => now(), 'version' => $work->version + 1]);
            }
        });

        $this->postJson(route('api.v1.orders.deliver', $this->order), $payload)
            ->assertOk()->assertJsonPath('data.items.0.status', 'delivered');
        $this->assertTrue($raced);
    }

    public function test_entregar_item_que_nao_esta_pronto_recusa(): void
    {
        $this->makeReady('X-Burger');
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.deliver', $this->order), ['item_ids' => [$this->itemId('X-Burger'), $this->itemId('Refri')]])
            ->assertUnprocessable()->assertJsonPath('message', 'Refri ainda não está pronto.');

        $this->assertNull($this->work('X-Burger')->delivered_at);
    }

    public function test_entregar_item_de_outra_comanda_404(): void
    {
        $other = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $this->admin->company_id]), 1, $this->admin);
        $this->makeReady('X-Burger');
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.deliver', $other), ['item_ids' => [$this->itemId('X-Burger')]])->assertNotFound();
    }

    public function test_entregar_exige_permissao(): void
    {
        $this->makeReady('X-Burger');
        Sanctum::actingAs($this->userWith(['tables.operate'], $this->admin->company));

        $this->postJson(route('api.v1.orders.deliver', $this->order), ['item_ids' => [$this->itemId('X-Burger')]])->assertForbidden();
        $this->assertNull($this->work('X-Burger')->delivered_at);
    }

    public function test_comanda_mostra_pedido_do_qr_pendente_e_itens_entregaveis(): void
    {
        $this->makeReady('X-Burger');
        $receipt = $this->qrReceipt();
        Sanctum::actingAs($this->waiter);

        $this->getJson(route('api.v1.orders.show', $this->order))->assertOk()
            ->assertJsonPath('data.items.0.deliverable', true)
            ->assertJsonPath('data.items.1.deliverable', false)
            ->assertJsonPath('data.pending_receipts.0.id', $receipt->id)
            ->assertJsonPath('data.pending_receipts.0.total', '16.00')
            ->assertJsonPath('data.pending_receipts.0.items.0.product_name', 'Suco')
            ->assertJsonPath('data.pending_receipts.0.items.0.quantity', 2)
            ->assertJsonPath('data.pending_receipts.0.items.0.notes', 'Sem gelo');
    }

    public function test_confirmar_pedido_do_qr(): void
    {
        $receipt = $this->qrReceipt();
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.receipts.confirm', $receipt))->assertOk()
            ->assertJsonCount(0, 'data.pending_receipts')->assertJsonCount(3, 'data.items');

        $this->assertSame(MenuOrderReceipt::CONFIRMED, $receipt->fresh()->status);
    }

    public function test_recusar_pedido_do_qr_exige_motivo(): void
    {
        $receipt = $this->qrReceipt();
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.receipts.reject', $receipt), [])->assertJsonValidationErrors('reason');
        $this->postJson(route('api.v1.receipts.reject', $receipt), ['reason' => 'Acabou o suco'])->assertOk()->assertJsonCount(0, 'data.pending_receipts');

        $this->assertSame(MenuOrderReceipt::REJECTED, $receipt->fresh()->status);
    }

    public function test_confirmar_exige_permissao(): void
    {
        $receipt = $this->qrReceipt();
        Sanctum::actingAs($this->userWith(['tables.operate'], $this->admin->company));

        $this->postJson(route('api.v1.receipts.confirm', $receipt))->assertForbidden();
    }

    public function test_pedido_do_qr_de_outra_empresa_404(): void
    {
        $foreign = MenuOrderReceipt::factory()->create();
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.receipts.confirm', $foreign))->assertNotFound();
        $this->postJson(route('api.v1.receipts.reject', $foreign), ['reason' => 'x'])->assertNotFound();
        $this->assertSame(MenuOrderReceipt::PENDING, $foreign->fresh()->status);
    }
}
