<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DeliveryZone;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Provas de conceito da auditoria. Cada teste PASSA quando o defeito/comportamento descrito EXISTE. */
class AuditProofTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DiningTable $table;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminOf();
        $this->table = DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 7]);
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'sale_price' => '40', 'cost_price' => '12', 'stock_quantity' => '50']);
    }

    private function guest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function qr(array $o = [])
    {
        return $this->post("/m/{$this->table->qr_token}", ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'quantity' => 1]], ...$o]);
    }

    private function closeOrder(TableOrder $order, string $subtotal)
    {
        return $this->actingAs($this->admin)->post("/mesas/comandas/{$order->id}/fechar", ['client_uuid' => (string) Str::uuid(), 'expected_subtotal' => $subtotal, 'charge_service' => false, 'payments' => [['method' => 'pix', 'amount' => $subtotal]]]);
    }

    private function deliveryPayload(Customer $c, array $o = []): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'customer_id' => $c->id, 'type' => 'delivery', 'items' => [['product_id' => $this->product->id, 'quantity' => '1']], 'address' => ['street' => 'Rua A', 'number' => '10', 'district' => 'Centro'], 'delivery_fee' => '7', 'payment_method' => 'pix', ...$o];
    }

    private function deliveredOrder(): DeliveryOrder
    {
        $courier = $this->userWith(['deliveries.courier'], $this->admin->company);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $this->actingAs($this->admin)->post('/entregas', $this->deliveryPayload($customer))->assertSessionHasNoErrors();
        $order = DeliveryOrder::latest('id')->first();
        foreach ([[], [], ['courier_id' => $courier->id], []] as $extra) {
            $this->post("/entregas/{$order->id}/avancar", ['expected_status' => $order->fresh()->status, ...$extra])->assertSessionHasNoErrors();
        }

        return $order->fresh();
    }

    private function fiado(): array
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id, 'allow_credit' => true, 'credit_limit' => '100', 'credit_days' => 15]);
        $this->openCash($this->admin);
        $this->actingAs($this->admin)->post('/vendas', ['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => '2', 'expected_price' => '40.00']], 'payments' => [['method' => 'fiado', 'amount' => '80']]])->assertSessionHasNoErrors();

        return [$customer, Bill::sole(), Sale::sole()];
    }

    /** A1: pessoa remota lança item na conta de outro cliente e ele é cobrado. */
    public function test_p01_token_estatico_permite_pedido_remoto_na_conta_de_terceiro(): void
    {
        $this->actingAs($this->admin)->post("/mesas/{$this->table->id}/abrir", ['people' => 2])->assertSessionHasNoErrors();
        $order = TableOrder::sole();
        $this->guest();

        $this->qr(['items' => [['product_id' => $this->product->id, 'quantity' => 50]]])->assertRedirect();

        $this->openCash($this->admin);
        $this->closeOrder($order->fresh(), '2000.00')->assertSessionHasNoErrors();
        $this->assertSame('2000.00', Sale::sole()->total);
    }

    /** A1: o GET público expõe a conta aberta a qualquer portador do token. */
    public function test_p02_token_expoe_itens_e_total_da_conta_aberta(): void
    {
        $this->qr();
        $this->get("/m/{$this->table->qr_token}")->assertOk()->assertInertia(fn ($p) => $p->where('order.subtotal', fn ($v) => (float) $v === 40.0)->etc());
    }

    /** A1: volume máximo de uma única requisição pública. */
    public function test_p03_uma_requisicao_publica_lanca_30_linhas_de_50_unidades(): void
    {
        $items = array_map(fn ($i) => ['product_id' => $this->product->id, 'quantity' => 50], range(1, 30));
        $this->qr(['items' => $items])->assertRedirect();
        $this->assertSame(60000.0, (float) TableOrder::sole()->items()->sum('total'));
    }

    /** A2: o pedido do QR não é enviado à cozinha, embora a mensagem diga que sim. */
    public function test_p04_pedido_qr_nao_e_enviado_a_cozinha_e_mensagem_afirma_o_contrario(): void
    {
        $response = $this->qr();
        $response->assertSessionHas('success', fn ($m) => str_contains($m, 'cozinha'));
        $this->assertNull(TableOrder::sole()->items()->sole()->kitchen_batch);
        $this->assertSame(0, (int) TableOrder::sole()->kitchen_batch);
    }

    /** A3: conta fecha com item que nunca foi à cozinha. */
    public function test_p05_conta_fecha_com_item_nunca_enviado_a_cozinha(): void
    {
        $this->qr();
        $order = TableOrder::sole();
        $this->openCash($this->admin);
        $this->closeOrder($order, '40.00')->assertSessionHasNoErrors();
        $this->assertNull($order->items()->sole()->kitchen_batch);
        $this->assertSame('closed', $order->fresh()->status);
    }

    /** A3: SendToKitchen não verifica se a comanda está aberta. */
    public function test_p06_comanda_fechada_ainda_aceita_envio_a_cozinha(): void
    {
        $this->qr();
        $order = TableOrder::sole();
        $this->openCash($this->admin);
        $this->closeOrder($order, '40.00')->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/mesas/comandas/{$order->id}/cozinha");
        $this->assertSame(1, (int) $order->fresh()->kitchen_batch);
    }

    /** A4: página aberta com mesa livre (known_order_id nulo) cai na comanda de quem chegou depois. */
    public function test_p07_pagina_com_mesa_livre_cai_na_conta_de_quem_chegou_depois(): void
    {
        $this->actingAs($this->admin)->post("/mesas/{$this->table->id}/abrir", ['people' => 2]);
        $this->guest();
        $this->qr(['known_order_id' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, TableOrder::sole()->items()->count());
    }

    /** A4: retry do mesmo client_uuid após fechar a conta abre comanda nova com pedido duplicado. */
    public function test_p08_retry_apos_fechamento_duplica_em_nova_comanda(): void
    {
        $uuid = (string) Str::uuid();
        $this->qr(['client_uuid' => $uuid]);
        $order = TableOrder::sole();
        $this->openCash($this->admin);
        $this->closeOrder($order, '40.00')->assertSessionHasNoErrors();
        $this->guest();
        $this->qr(['client_uuid' => $uuid]);
        $this->assertSame(2, TableOrder::count());
    }

    /** Pedido público sem saldo é aceito; o fechamento deixa saldo negativo. */
    public function test_p09_qr_aceita_produto_sem_estoque_e_fechamento_negativa_saldo(): void
    {
        $this->product->forceFill(['stock_quantity' => '0', 'track_stock' => true])->save();
        $this->qr()->assertSessionHasNoErrors();
        $order = TableOrder::sole();
        $this->openCash($this->admin);
        $this->closeOrder($order, '40.00')->assertSessionHasNoErrors();
        $this->assertSame('-1.000', $this->product->fresh()->stock_quantity);
    }

    /** D1: taxa de entrega vem do cliente HTTP, sem ligação com o bairro cadastrado. */
    public function test_p10_taxa_de_entrega_e_livre(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $this->actingAs($this->admin)->post('/entregas/bairros', ['name' => 'Centro', 'fee' => '8'])->assertSessionHasNoErrors();
        $this->post('/entregas', $this->deliveryPayload($customer, ['delivery_fee' => '0']))->assertSessionHasNoErrors();
        $this->assertSame('0.00', DeliveryOrder::sole()->delivery_fee);
    }

    /** D2: pedido já entregue, sem acerto, pode ser cancelado. */
    public function test_p11_cancela_pedido_ja_entregue_sem_acerto(): void
    {
        $order = $this->deliveredOrder();
        $this->assertSame('delivered', $order->status);
        $this->actingAs($this->admin)->post("/entregas/{$order->id}/cancelar", ['reason' => 'Teste'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    /** D3: cancelar a venda de um pedido acertado não altera o pedido. */
    public function test_p12_cancelar_venda_deixa_entrega_como_entregue_e_acertada(): void
    {
        $order = $this->deliveredOrder();
        $this->openCash($this->admin);
        $this->actingAs($this->admin)->post("/entregas/{$order->id}/acertar", ['client_uuid' => (string) Str::uuid(), 'payments' => [['method' => 'pix', 'amount' => '47']]])->assertSessionHasNoErrors();
        $sale = Sale::sole();
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'Devolvido'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
    }

    public function test_p13_cancelar_venda_nao_altera_a_comanda(): void
    {
        $this->qr();
        $order = TableOrder::sole();
        $this->openCash($this->admin);
        $this->closeOrder($order, '40.00')->assertSessionHasNoErrors();
        $sale = Sale::sole();
        $this->post("/vendas/{$sale->id}/cancelar", ['reason' => 'Erro'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
    }

    /** F1: baixa de valor menor quita a dívida inteira e libera limite. */
    public function test_p14_baixa_de_um_real_quita_fiado_de_oitenta(): void
    {
        [$customer, $bill] = $this->fiado();
        $this->assertSame(0, bccomp((string) $customer->openCredit(), '80', 2));
        $this->post("/financeiro/contas/{$bill->id}/baixa", ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => '1', 'payment_method' => 'pix'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame(0, bccomp((string) $customer->fresh()->openCredit(), '0', 2));
    }

    /** F2: conta gerada por venda pode ter o valor editado, sem tocar a venda. */
    public function test_p15_conta_de_fiado_pode_ter_valor_editado(): void
    {
        [, $bill, $sale] = $this->fiado();
        $this->put("/financeiro/contas/{$bill->id}", ['description' => 'x', 'category' => $bill->category, 'amount' => '5', 'due_date' => today()->addDay()->format('Y-m-d')])->assertSessionHasNoErrors();
        $this->assertSame('5.00', $bill->fresh()->amount);
        $this->assertSame('80.00', $sale->fresh()->total);
    }

    /** F2: conta de fiado cancelada direto: venda segue concluída e nada a receber. */
    public function test_p16_cancelar_conta_de_fiado_mantem_venda_sem_recebivel(): void
    {
        [$customer, $bill, $sale] = $this->fiado();
        $this->post("/financeiro/contas/{$bill->id}/cancelar", ['reason' => 'Motivo'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $bill->fresh()->status);
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(0, bccomp((string) $customer->fresh()->openCredit(), '0', 2));
    }

    /** S1: sem trustProxies, clientes distintos atrás do proxy dividem o mesmo limite. */
    public function test_p17_limite_e_compartilhado_entre_clientes_atras_de_proxy(): void
    {
        foreach (range(1, 20) as $i) {
            $this->withHeader('X-Forwarded-For', "203.0.113.{$i}")->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->post("/m/{$this->table->qr_token}", ['client_uuid' => 'x', 'items' => []]);
        }
        $this->withHeader('X-Forwarded-For', '198.51.100.9')->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->post("/m/{$this->table->qr_token}", ['client_uuid' => 'x', 'items' => []])->assertTooManyRequests();
    }

    /** S3: precognition revela e-mail e CNPJ já cadastrados. */
    public function test_p18_precognition_enumera_email_e_cnpj(): void
    {
        $this->app['auth']->forgetGuards();
        $response = $this->withHeaders(['Precognition' => 'true', 'Precognition-Validate-Only' => 'email,document'])->postJson('/cadastro', ['email' => $this->admin->email, 'document' => $this->admin->company->document]);
        fwrite(STDERR, "\nP18 status=".$response->getStatusCode().' keys='.json_encode(array_keys($response->json('errors') ?? []))."\n");
        $response->assertStatus(422);
    }

    /** S4/S6: login inválido com e-mails rotativos não é limitado e grava auditoria. */
    public function test_p19_login_com_emails_rotativos_nao_limita_e_infla_auditoria(): void
    {
        $before = AuditLog::count();
        $statuses = [];
        foreach (range(1, 40) as $i) {
            $statuses[] = $this->post('/login', ['email' => "x{$i}@example.test", 'password' => 'errada'])->getStatusCode();
        }
        fwrite(STDERR, "\nP19 audit_logs +".(AuditLog::count() - $before).' statuses='.json_encode(array_count_values($statuses))."\n");
        $this->assertGreaterThanOrEqual(40, AuditLog::count() - $before);
    }

    /** S5: parâmetros de filtro como array. */
    public function test_p20_filtro_como_array(): void
    {
        $out = [];
        foreach (['/clientes?search[]=x', '/clientes/buscar?q[]=x', '/produtos?search[]=x', '/fornecedores?search[]=x', '/usuarios?search[]=x', '/estoque?search[]=x', '/vendas?search[]=x', '/entregas/historico?search[]=x', '/produtos/buscar?q[]=x'] as $u) {
            $out[$u] = $this->actingAs($this->admin)->get($u)->getStatusCode();
        }
        fwrite(STDERR, "\nP20 ".json_encode($out)."\n");
        $this->assertContains(500, $out);
    }

    /** S5: product_id não inteiro. */
    public function test_p21_product_id_nao_inteiro(): void
    {
        $this->openCash($this->admin);
        $r = $this->actingAs($this->admin)->post('/vendas', ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id.'abc', 'quantity' => '1', 'expected_price' => '40.00']], 'payments' => [['method' => 'pix', 'amount' => '40']]]);
        fwrite(STDERR, "\nP21 sqlite status=".$r->getStatusCode()."\n");
        $this->assertTrue(true);
    }

    /** Varredura: TODA rota com parâmetro, como admin de outra empresa, deve dar 404. */
    public function test_p22_varredura_cross_tenant_em_todas_as_rotas_com_id(): void
    {
        $this->qr();
        $victimOrder = TableOrder::sole();
        $victimItem = $victimOrder->items()->sole();
        $c = $this->admin->company_id;
        $customer = Customer::factory()->create(['company_id' => $c]);
        $this->openCash($this->admin);
        $this->actingAs($this->admin)->post('/entregas', $this->deliveryPayload($customer))->assertSessionHasNoErrors();
        $this->post('/entregas/bairros', ['name' => 'Zona', 'fee' => '8'])->assertSessionHasNoErrors();
        $this->post('/vendas', ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'expected_price' => '40.00']], 'payments' => [['method' => 'pix', 'amount' => '40']]])->assertSessionHasNoErrors();
        $this->post('/entradas', ['entry_date' => today()->format('Y-m-d'), 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '1']]]);
        $ids = [
            'table' => $this->table->id, 'order' => $victimOrder->id, 'item' => $victimItem->id, 'batch' => 1,
            'customer' => $customer->id, 'zone' => DeliveryZone::sole()->id, 'delivery' => DeliveryOrder::sole()->id,
            'bill' => Bill::factory()->create(['company_id' => $c])->id, 'cashSession' => CashSession::sole()->id,
            'sale' => Sale::sole()->id, 'product' => $this->product->id, 'category' => Category::factory()->create(['company_id' => $c])->id,
            'supplier' => Supplier::factory()->create(['company_id' => $c])->id, 'entry' => StockEntry::query()->value('id') ?? 0,
            'user' => $this->admin->id, 'role' => $this->admin->role_id,
        ];
        $this->app['auth']->forgetGuards();

        $snapshots = fn () => [Sale::withoutGlobalScopes()->where('company_id', $c)->count(), TableOrder::withoutGlobalScopes()->where('company_id', $c)->where('status', 'open')->count(), Bill::withoutGlobalScopes()->where('company_id', $c)->where('status', 'open')->count()];
        $before = $snapshots();

        $attacker = $this->adminOf();
        $results = [];
        $unmapped = [];
        foreach (app('router')->getRoutes() as $route) {
            $params = $route->parameterNames();
            $mw = $route->gatherMiddleware();
            if ($params === [] || ! in_array('company', $mw, true)) {
                continue;
            }
            $uri = $route->uri();
            foreach ($params as $p) {
                if (! isset($ids[$p])) {
                    $unmapped[$route->uri()] = $p;

                    continue 2;
                }
                $uri = str_replace('{'.$p.'}', (string) $ids[$p], $uri);
            }
            $method = $route->methods()[0];
            $status = $this->actingAs($attacker)->call($method, '/'.$uri, ['reason' => 'x', 'client_uuid' => (string) Str::uuid(), 'paid_at' => today()->format('Y-m-d'), 'paid_amount' => '1', 'payment_method' => 'pix', 'expected_status' => 'received'])->getStatusCode();
            $results[$method.' '.$route->uri()] = $status;
        }
        $bad = array_filter($results, fn ($s) => $s !== 404);
        fwrite(STDERR, "\nP22 rotas=".count($results).' nao404='.json_encode($bad).' semMapa='.json_encode($unmapped).' entry='.$ids['entry']."\n");
        $this->assertSame($before, $snapshots());
    }

    /** Varredura: IDs de outra empresa no corpo das requisições devem ser recusados. */
    public function test_p23_varredura_cross_tenant_em_ids_no_corpo(): void
    {
        $other = $this->adminOf();
        $o = $other->company_id;
        $fProduct = Product::factory()->create(['company_id' => $o, 'sale_price' => '1']);
        $fCustomer = Customer::factory()->create(['company_id' => $o, 'allow_credit' => true]);
        $fSupplier = Supplier::factory()->create(['company_id' => $o]);
        $fCategory = Category::factory()->create(['company_id' => $o]);
        $fCourier = $this->userWith(['deliveries.courier'], $other->company);
        $fTable = DiningTable::factory()->create(['company_id' => $o, 'number' => 99]);
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $this->openCash($this->admin);
        $this->actingAs($this->admin);

        $checks = [];
        $check = function (string $name, $response) use (&$checks) {
            $checks[$name] = $response->getStatusCode();
        };
        $check('venda.produto', $this->post('/vendas', ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $fProduct->id, 'quantity' => '1', 'expected_price' => '1']], 'payments' => [['method' => 'pix', 'amount' => '1']]]));
        $check('venda.cliente', $this->post('/vendas', ['client_uuid' => (string) Str::uuid(), 'customer_id' => $fCustomer->id, 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'expected_price' => '40.00']], 'payments' => [['method' => 'fiado', 'amount' => '40']]]));
        $check('entrega.cliente', $this->post('/entregas', $this->deliveryPayload($fCustomer)));
        $check('entrega.produto', $this->post('/entregas', $this->deliveryPayload($customer, ['items' => [['product_id' => $fProduct->id, 'quantity' => '1']]])));
        $check('entrada.fornecedor', $this->post('/entradas', ['supplier_id' => $fSupplier->id, 'entry_date' => today()->format('Y-m-d'), 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '1']]]));
        $check('entrada.produto', $this->post('/entradas', ['entry_date' => today()->format('Y-m-d'), 'items' => [['product_id' => $fProduct->id, 'quantity' => '1', 'unit_cost' => '1']]]));
        $check('produto.categoria', $this->post('/produtos', ['name' => 'X', 'category_id' => $fCategory->id, 'unit' => 'UN', 'sale_price' => '1']));
        $check('conta.fornecedor', $this->post('/financeiro/pagar', ['description' => 'a', 'category' => 'Aluguel', 'amount' => '1', 'due_date' => today()->format('Y-m-d'), 'supplier_id' => $fSupplier->id]));

        $this->post('/entregas', $this->deliveryPayload($customer));
        $order = DeliveryOrder::sole();
        $this->post("/entregas/{$order->id}/avancar", ['expected_status' => $order->status]);
        $this->post("/entregas/{$order->id}/avancar", ['expected_status' => $order->fresh()->status]);
        $check('entrega.entregador', $this->post("/entregas/{$order->id}/avancar", ['expected_status' => $order->fresh()->status, 'courier_id' => $fCourier->id]));

        $this->post("/mesas/{$this->table->id}/abrir", ['people' => 1]);
        $tOrder = TableOrder::sole();
        $check('mesa.transferir', $this->post("/mesas/comandas/{$tOrder->id}/transferir", ['dining_table_id' => $fTable->id]));
        $check('mesa.item', $this->post("/mesas/comandas/{$tOrder->id}/itens", ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $fProduct->id, 'quantity' => '1']]]));

        fwrite(STDERR, "\nP23 ".json_encode($checks, JSON_UNESCAPED_UNICODE)."\n");
        $this->assertSame(1, DeliveryOrder::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockEntry::count());
        $this->assertSame(0, Bill::count());
        $this->assertSame($this->table->id, $tOrder->fresh()->dining_table_id);
        $this->assertSame(0, $tOrder->items()->count());
    }

    /** Varredura: usuário sem permissão nenhuma em todas as rotas da empresa. */
    public function test_p24_varredura_de_autorizacao_com_usuario_sem_permissao(): void
    {
        $c = $this->admin->company_id;
        $user = $this->userWith([], $this->admin->company);
        $customer = Customer::factory()->create(['company_id' => $c]);
        $this->qr();
        $order = TableOrder::sole();
        $this->openCash($this->admin);
        $this->actingAs($this->admin)->post('/vendas', ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'expected_price' => '40.00']], 'payments' => [['method' => 'pix', 'amount' => '40']]])->assertSessionHasNoErrors();
        $this->post('/entregas', $this->deliveryPayload($customer))->assertSessionHasNoErrors();
        $this->post('/entregas/bairros', ['name' => 'Zona', 'fee' => '8']);
        $this->post('/entradas', ['entry_date' => today()->format('Y-m-d'), 'notes' => 'x', 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '1']]])->assertSessionHasNoErrors();
        $entryId = StockEntry::query()->value('id');
        $ids = ['table' => $this->table->id, 'order' => $order->id, 'item' => $order->items()->sole()->id, 'batch' => 1, 'customer' => $customer->id, 'zone' => DeliveryZone::sole()->id, 'delivery' => DeliveryOrder::sole()->id, 'bill' => Bill::factory()->create(['company_id' => $c])->id, 'cashSession' => CashSession::sole()->id, 'sale' => Sale::sole()->id, 'product' => $this->product->id, 'category' => Category::factory()->create(['company_id' => $c])->id, 'supplier' => Supplier::factory()->create(['company_id' => $c])->id, 'entry' => $entryId, 'user' => $this->admin->id, 'role' => $this->admin->role_id, 'type' => 'pagar', 'report' => 'vendas'];
        $this->app['auth']->forgetGuards();
        $results = [];
        foreach (app('router')->getRoutes() as $route) {
            $mw = $route->gatherMiddleware();
            if (! in_array('company', $mw, true)) {
                continue;
            }
            $uri = $route->uri();
            foreach ($route->parameterNames() as $p) {
                $uri = str_replace('{'.$p.'}', (string) ($ids[$p] ?? 1), $uri);
            }
            $method = $route->methods()[0];
            $results[$method.' '.$route->uri()] = $this->actingAs($user)->call($method, '/'.ltrim($uri, '/'), [])->getStatusCode();
        }
        $bad = array_filter($results, fn ($s) => $s !== 403);
        fwrite(STDERR, "\nP24 rotas=".count($results).' nao403='.json_encode($bad)."\n");
        $this->assertTrue(true);
    }
}
