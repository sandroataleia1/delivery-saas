<?php

namespace Tests\Feature;

use App\Actions\SyncRecipe;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\Product;
use App\Models\ProductionWork;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $courier;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->productionAdminOf();
        $this->courier = $this->userWith(['deliveries.courier'], $this->admin->company);
        $this->customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Maria']);
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'sale_price' => '30', 'stock_quantity' => '10']);
        $this->openCash($this->admin);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $this->customer->id,
            'type' => 'delivery',
            'items' => [['product_id' => $this->product->id, 'quantity' => '2', 'notes' => 'Bem passada']],
            'address' => ['street' => 'Rua A', 'number' => '10', 'district' => 'Centro', 'reference' => 'Portão azul'],
            'delivery_fee' => '7',
            'payment_method' => 'cash',
            'change_for' => '100',
            ...$overrides,
        ];
    }

    private function order(array $overrides = []): DeliveryOrder
    {
        $this->actingAs($this->admin)->post('/entregas', $this->payload($overrides))->assertSessionHasNoErrors();

        return DeliveryOrder::latest('id')->first();
    }

    private function advance(DeliveryOrder $order, array $extra = [])
    {
        if (in_array($order->fresh()->status, ['received', 'preparing'], true)) {
            $work = ProductionWork::whereIn('delivery_order_item_id', $order->items()->pluck('id'))->firstOrFail();

            return $this->post(route('production.transition', $work->id), ['expected_version' => $work->version, 'target' => $work->status === 'waiting' ? 'preparing' : 'ready']);
        }

        return $this->post("/entregas/{$order->id}/avancar", ['expected_status' => $order->fresh()->status, ...$extra]);
    }

    private function delivered(?DeliveryOrder $order = null): DeliveryOrder
    {
        $order ??= $this->order();

        $this->advance($order);
        $this->advance($order);
        $this->advance($order, ['courier_id' => $this->courier->id]);
        $this->advance($order);

        return $order->fresh();
    }

    private function settlement(array $overrides = []): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'payments' => [['method' => 'cash', 'amount' => '100']], ...$overrides];
    }

    public function test_cria_pedido_com_preco_do_servidor_e_grava_o_endereco_no_cliente(): void
    {
        $order = $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_price' => '0.01']]]);

        $this->assertSame('60.00', $order->subtotal);
        $this->assertSame('67.00', $order->total());
        $this->assertSame(DeliveryOrder::RECEIVED, $order->status);
        $this->assertSame('Rua A, 10 - Centro', $order->address());
        $this->assertSame('Rua A', $this->customer->addresses()->sole()->street);
    }

    public function test_reenviar_o_mesmo_pedido_nao_duplica(): void
    {
        $payload = $this->payload();

        $this->actingAs($this->admin)->post('/entregas', $payload);
        $this->post('/entregas', $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, DeliveryOrder::count());
    }

    public function test_criar_nao_baixa_estoque_nem_gera_venda(): void
    {
        $this->order();

        $this->assertSame('10.000', $this->product->fresh()->stock_quantity);
        $this->assertSame(0, Sale::count());
    }

    public function test_endereco_igual_nao_duplica(): void
    {
        $this->order();
        $this->travel(5)->seconds();
        $this->order();

        $this->assertSame(1, $this->customer->addresses()->count());
    }

    public function test_entrega_exige_endereco_e_retirada_nao(): void
    {
        $this->actingAs($this->admin)->post('/entregas', $this->payload(['address' => null]))->assertSessionHasErrors('address');

        $order = $this->order(['type' => 'pickup', 'address' => null, 'delivery_fee' => '9']);

        $this->assertSame('0.00', $order->delivery_fee);
    }

    public function test_etapas_na_ordem_e_entregador_obrigatorio_para_sair(): void
    {
        $order = $this->order();

        $this->advance($order)->assertSessionHasNoErrors();
        $this->assertSame(DeliveryOrder::PREPARING, $order->fresh()->status);

        $this->advance($order)->assertSessionHasNoErrors();
        $this->advance($order)->assertSessionHasErrors('courier_id');
        $this->assertSame(DeliveryOrder::READY, $order->fresh()->status);

        $this->advance($order, ['courier_id' => $this->courier->id])->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(DeliveryOrder::OUT, $order->status);
        $this->assertSame($this->courier->id, $order->courier_id);
        $this->assertNotNull($order->dispatched_at);
    }

    public function test_retirada_nao_passa_por_rota(): void
    {
        $order = $this->order(['type' => 'pickup', 'address' => null]);

        $this->advance($order);
        $this->advance($order);
        $this->advance($order)->assertSessionHasNoErrors();

        $this->assertSame(DeliveryOrder::DELIVERED, $order->fresh()->status);
    }

    public function test_clique_duplo_nao_pula_etapa(): void
    {
        $order = $this->order();

        $this->advance($order);
        $this->post("/entregas/{$order->id}/avancar", ['expected_status' => DeliveryOrder::RECEIVED])->assertSessionHasErrors('order');

        $this->assertSame(DeliveryOrder::PREPARING, $order->fresh()->status);
    }

    public function test_usuario_comum_nao_e_aceito_como_entregador(): void
    {
        $order = $this->order();
        $this->advance($order);
        $this->advance($order);

        $this->advance($order, ['courier_id' => $this->admin->id + 0])->assertSessionHasNoErrors();

        $plain = $this->userWith([], $this->admin->company);
        $second = $this->order(['notes' => 'outro']);
        $this->advance($second);
        $this->advance($second);
        $this->advance($second, ['courier_id' => $plain->id])->assertSessionHasErrors('courier_id');
    }

    public function test_entregador_ve_e_conclui_so_as_proprias_entregas(): void
    {
        $order = $this->order();
        $this->advance($order);
        $this->advance($order);
        $this->advance($order, ['courier_id' => $this->courier->id]);

        $this->actingAs($this->courier)->get('/entregas/minhas')->assertInertia(fn (Assert $page) => $page->has('orders', 1));

        $other = $this->userWith(['deliveries.courier'], $this->admin->company);
        $this->actingAs($other)->get('/entregas/minhas')->assertInertia(fn (Assert $page) => $page->has('orders', 0));
        $this->post("/entregas/{$order->id}/avancar", ['expected_status' => DeliveryOrder::OUT])->assertForbidden();

        $this->actingAs($this->courier)->post("/entregas/{$order->id}/avancar", ['expected_status' => DeliveryOrder::OUT])->assertSessionHasNoErrors();

        $this->assertSame(DeliveryOrder::DELIVERED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_entregador_nao_acessa_quadro_nem_acerto(): void
    {
        $order = $this->order();

        $this->actingAs($this->courier);
        $this->get('/entregas')->assertForbidden();
        $this->post("/entregas/{$order->id}/acertar", $this->settlement())->assertForbidden();
        $this->post("/entregas/{$order->id}/avancar", ['expected_status' => DeliveryOrder::RECEIVED])->assertForbidden();
    }

    public function test_acerto_gera_venda_com_taxa_baixa_estoque_e_entra_no_caixa(): void
    {
        $order = $this->delivered();

        $this->post("/entregas/{$order->id}/acertar", $this->settlement())->assertSessionHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('delivery', $sale->origin);
        $this->assertSame('7.00', $sale->delivery_fee);
        $this->assertSame('67.00', $sale->total);
        $this->assertSame('33.00', $sale->change);
        $this->assertSame($this->customer->id, $sale->customer_id);
        $this->assertSame('8.000', $this->product->fresh()->stock_quantity);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
    }

    public function test_preco_fica_o_do_pedido(): void
    {
        $order = $this->order();
        $this->product->sale_price = '99';
        $this->product->save();
        $this->delivered($order);

        $this->post("/entregas/{$order->id}/acertar", $this->settlement())->assertSessionHasNoErrors();

        $this->assertSame('67.00', Sale::sole()->total);
    }

    public function test_acerto_exige_entregue_e_caixa_aberto(): void
    {
        $order = $this->order();

        $this->post("/entregas/{$order->id}/acertar", $this->settlement())->assertSessionHasErrors('order');

        $this->delivered($order);

        $operator = $this->userWith(['deliveries.settle'], $this->admin->company);

        $this->actingAs($operator)->post("/entregas/{$order->id}/acertar", $this->settlement())->assertSessionHasErrors('cash');
        $this->assertNull($order->fresh()->sale_id);
    }

    public function test_reenviar_o_acerto_nao_gera_outra_venda(): void
    {
        $order = $this->delivered();
        $payload = $this->settlement();

        $this->post("/entregas/{$order->id}/acertar", $payload);
        $this->post("/entregas/{$order->id}/acertar", $payload)->assertSessionHasNoErrors();
        $this->post("/entregas/{$order->id}/acertar", $this->settlement())->assertSessionHasErrors('order');

        $this->assertSame(1, Sale::count());
    }

    public function test_entrega_com_ficha_tecnica_baixa_insumos_no_acerto(): void
    {
        $cheese = Product::factory()->create(['company_id' => $this->admin->company_id, 'unit' => 'KG', 'stock_quantity' => '1']);
        $pizza = Product::factory()->create(['company_id' => $this->admin->company_id, 'track_stock' => false, 'sale_price' => '30']);
        app(SyncRecipe::class)->handle($pizza, [['ingredient_id' => $cheese->id, 'quantity' => '0.25']]);

        $order = $this->delivered($this->order(['items' => [['product_id' => $pizza->id, 'quantity' => '1']]]));

        $this->post("/entregas/{$order->id}/acertar", $this->settlement(['payments' => [['method' => 'pix', 'amount' => '37']]]))->assertSessionHasNoErrors();

        $this->assertSame('0.750', $cheese->fresh()->stock_quantity);
        $this->assertSame(StockMovement::SALE_INGREDIENT, StockMovement::sole()->type);
    }

    public function test_fiado_na_entrega_usa_o_cliente_do_pedido(): void
    {
        $this->customer->update(['allow_credit' => true]);
        $order = $this->delivered();

        $this->post("/entregas/{$order->id}/acertar", $this->settlement(['payments' => [['method' => 'fiado', 'amount' => '67']]]))
            ->assertSessionHasNoErrors();

        $this->assertSame('67.00', Bill::sole()->amount);
        $this->assertSame($this->customer->id, Bill::sole()->customer_id);
    }

    public function test_cancelar_antes_do_acerto_nao_mexe_em_nada(): void
    {
        $order = $this->order();

        $this->post("/entregas/{$order->id}/cancelar", [])->assertSessionHasErrors('reason');
        $this->post("/entregas/{$order->id}/cancelar", ['reason' => 'Cliente desistiu'])->assertSessionHas('success');

        $this->assertTrue($order->fresh()->isCancelled());
        $this->assertSame('10.000', $this->product->fresh()->stock_quantity);
    }

    public function test_depois_do_acerto_quem_cancela_e_a_venda(): void
    {
        $order = $this->delivered();
        $this->post("/entregas/{$order->id}/acertar", $this->settlement());

        $this->post("/entregas/{$order->id}/cancelar", ['reason' => 'x'])->assertSessionHasErrors('reason');
    }

    public function test_quadro_mostra_so_pedidos_pendentes(): void
    {
        $settled = $this->delivered();
        $this->post("/entregas/{$settled->id}/acertar", $this->settlement());
        $this->travel(5)->seconds();
        $open = $this->order();
        $this->travel(5)->seconds();
        $cancelled = $this->order();
        $this->post("/entregas/{$cancelled->id}/cancelar", ['reason' => 'x']);

        $this->get('/entregas')->assertInertia(
            fn (Assert $page) => $page->has('orders', 1)->where('orders.0.id', $open->id)
        );
    }

    public function test_lista_de_entregadores_so_tem_quem_tem_a_permissao(): void
    {
        $this->actingAs($this->admin)->get('/entregas')->assertInertia(
            fn (Assert $page) => $page->where('couriers', fn ($couriers) => collect($couriers)->pluck('id')->contains($this->courier->id))
        );
    }

    public function test_taxa_por_bairro(): void
    {
        $this->actingAs($this->admin)->post('/entregas/bairros', ['name' => 'Centro', 'fee' => '6'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('delivery_zones', ['company_id' => $this->admin->company_id, 'name' => 'Centro', 'fee' => '6.00']);
        $this->post('/entregas/bairros', ['name' => 'Centro', 'fee' => '6'])->assertSessionHasErrors('name');
        $this->assertDatabaseCount('delivery_zones', 1);

        $this->get('/entregas/novo')->assertInertia(fn (Assert $page) => $page->where('zones.0.fee', '6.00'));
    }

    public function test_busca_de_cliente_por_telefone_traz_enderecos(): void
    {
        $this->order();

        $this->getJson('/clientes/buscar?q=(11)%209888')->assertJsonPath('0.id', $this->customer->id)->assertJsonPath('0.addresses.0.street', 'Rua A');
    }

    public function test_cadastro_rapido_de_cliente_por_json(): void
    {
        $this->actingAs($this->admin)->postJson('/clientes', [
            'name' => 'Novo',
            'phone' => '(11) 90000-0000',
            'addresses' => [['street' => 'Rua B', 'number' => '1', 'district' => 'Sul']],
        ])->assertOk()->assertJsonPath('name', 'Novo')->assertJsonPath('addresses.0.street', 'Rua B');
    }

    public function test_sem_permissao_de_fiado_nao_libera_nem_aumenta_o_limite(): void
    {
        $this->customer->forceFill(['allow_credit' => false, 'credit_limit' => null])->save();
        $clerk = $this->userWith(['customers.view', 'customers.manage'], $this->admin->company);

        $this->actingAs($clerk)->put("/clientes/{$this->customer->id}", [
            'name' => 'Maria',
            'allow_credit' => true,
            'credit_limit' => '99999',
            'credit_days' => 365,
        ])->assertSessionHasNoErrors();

        $customer = $this->customer->fresh();
        $this->assertFalse($customer->allow_credit);
        $this->assertNull($customer->credit_limit);
    }

    public function test_isolamento_entre_empresas(): void
    {
        $order = $this->order();
        $other = $this->productionAdminOf();
        $foreignCustomer = Customer::factory()->create(['company_id' => $other->company_id]);

        $this->actingAs($this->admin)->post('/entregas', $this->payload(['customer_id' => $foreignCustomer->id]))->assertSessionHasErrors('customer_id');

        $this->actingAs($other);
        $this->get('/entregas')->assertInertia(fn (Assert $page) => $page->has('orders', 0)->where('couriers', fn ($couriers) => ! collect($couriers)->pluck('id')->contains($this->courier->id)));
        $this->get("/entregas/{$order->id}")->assertNotFound();
        $this->post("/entregas/{$order->id}/avancar", ['expected_status' => 'received'])->assertNotFound();
        $this->get("/clientes/{$this->customer->id}")->assertNotFound();
        $this->getJson('/clientes/buscar?q=Maria')->assertExactJson([]);
    }

    public function test_perfil_entregador_padrao_tem_a_permissao(): void
    {
        $this->assertContains('deliveries.courier', collect(config('permissions.default_roles'))->firstWhere('name', 'Entregador')['permissions']);
        $this->assertInstanceOf(Role::class, $this->courier->role);
    }

    public function test_auditoria_acompanha_as_etapas(): void
    {
        $order = $this->order();
        $this->advance($order);

        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'DeliveryOrder', 'event' => 'created', 'user_id' => $this->admin->id]);
        $log = AuditLog::where('auditable_type', 'DeliveryOrder')->where('event', 'updated')->sole();
        $this->assertSame('Recebido', $log->old_values['status']);
        $this->assertSame('Em preparo', $log->new_values['status']);
    }
}
