<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Actions\PlaceMenuOrder;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Company;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Sale;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MenuTest extends TestCase
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
        $this->product = Product::factory()->create([
            'company_id' => $this->admin->company_id,
            'name' => 'Pizza',
            'description' => 'Molho e queijo',
            'sale_price' => '40',
            'cost_price' => '12',
            'stock_quantity' => '50',
        ]);
    }

    private function url(): string
    {
        return "/m/{$this->table->qr_token}";
    }

    private function order(array $overrides = [])
    {
        return $this->post($this->url(), [
            'client_uuid' => (string) Str::uuid(),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'notes' => 'Sem cebola']],
            ...$overrides,
        ]);
    }

    public function test_mesa_ganha_codigo_unico_ao_ser_criada(): void
    {
        $this->assertSame(32, strlen($this->table->qr_token));
        $this->assertNotSame($this->table->qr_token, DiningTable::factory()->create()->qr_token);
    }

    public function test_cardapio_abre_sem_login_e_mostra_a_mesa(): void
    {
        $this->get($this->url())->assertInertia(
            fn (Assert $page) => $page->component('menu/Show')
                ->where('table', 7)
                ->has('products', 1)
                ->where('products.0.name', 'Pizza')
                ->where('products.0.description', 'Molho e queijo')
                ->where('order', null)
        );
    }

    public function test_cardapio_nao_expoe_custo_nem_estoque(): void
    {
        $this->get($this->url())->assertInertia(
            fn (Assert $page) => $page->missing('products.0.cost_price')->missing('products.0.stock_quantity')
        );
    }

    public function test_insumo_e_inativo_ficam_fora(): void
    {
        $flour = Product::factory()->create(['company_id' => $this->admin->company_id]);
        $flour->sellable = false;
        $flour->save();
        Product::factory()->create(['company_id' => $this->admin->company_id, 'active' => false]);
        Product::factory()->create();

        $this->get($this->url())->assertInertia(fn (Assert $page) => $page->has('products', 1));

        $this->order(['items' => [['product_id' => $flour->id, 'quantity' => 1]]])->assertSessionHasErrors('items.0.product_id');
    }

    public function test_primeiro_pedido_abre_a_mesa_e_lanca_na_comanda(): void
    {
        $this->order()->assertSessionHas('success');

        $order = TableOrder::withoutGlobalScopes()->sole();
        $item = TableOrderItem::withoutGlobalScopes()->sole();

        $this->assertSame($this->table->id, $order->open_table_id);
        $this->assertSame('Cliente (QR Code)', $order->user_name);
        $this->assertNull($order->user_id);
        $this->assertSame($this->admin->company_id, $order->company_id);
        $this->assertSame('80.00', $item->total);
        $this->assertSame('Sem cebola', $item->notes);
        $this->assertSame('Cliente (QR Code)', $item->user_name);
    }

    public function test_pedido_cai_na_comanda_ja_aberta_pelo_garcom(): void
    {
        $this->actingAs($this->admin)->post("/mesas/{$this->table->id}/abrir", ['people' => 3]);
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();

        $this->flushSession();
        $this->order()->assertSessionHas('success');

        $this->assertSame(1, TableOrder::withoutGlobalScopes()->count());
        $this->assertSame(1, TableOrder::withoutGlobalScopes()->sole()->items()->withoutGlobalScopes()->count());
    }

    public function test_quem_chega_depois_entra_na_comanda_que_o_outro_abriu(): void
    {
        $open = new class(app(OpenTable::class)) extends OpenTable
        {
            public function __construct(private OpenTable $real) {}

            public function handle(DiningTable $table, int $people, ?User $user, ?string $guestName = null): TableOrder
            {
                $this->real->handle($table, 2, null, 'Outro celular');

                return $this->real->handle($table, $people, $user, $guestName);
            }
        };

        (new PlaceMenuOrder($open, app(AddOrderItems::class)))
            ->handle($this->table, [['product_id' => $this->product->id, 'quantity' => 1]], (string) Str::uuid(), null);

        $this->assertSame(1, TableOrder::withoutGlobalScopes()->count());
        $this->assertSame(1, TableOrderItem::withoutGlobalScopes()->count());
    }

    public function test_preco_vem_do_servidor(): void
    {
        $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => '0.01', 'total' => '0.01']]]);

        $this->assertSame('40.00', TableOrderItem::withoutGlobalScopes()->sole()->unit_price);
    }

    public function test_reenviar_nao_duplica(): void
    {
        $payload = ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]];

        $this->post($this->url(), $payload);
        $this->post($this->url(), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, TableOrderItem::withoutGlobalScopes()->count());
    }

    public function test_quantidade_tem_limite_por_item(): void
    {
        $this->order(['items' => [['product_id' => $this->product->id, 'quantity' => 51]]])->assertSessionHasErrors('items.0.quantity');
    }

    public function test_cliente_ve_o_que_pediu(): void
    {
        $this->order();

        $this->get($this->url())->assertInertia(
            fn (Assert $page) => $page->where('order.subtotal', '80.00')->has('order.items', 1)->where('order.items.0.product_name', 'Pizza')
        );
    }

    public function test_pedido_de_pagina_antiga_nao_cai_na_conta_do_proximo_cliente(): void
    {
        $this->order();
        $old = TableOrder::withoutGlobalScopes()->sole();

        $old->forceFill(['status' => TableOrder::CLOSED, 'open_table_id' => null])->saveQuietly();

        $this->post($this->url(), ['client_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'quantity' => 1]]]);
        $current = TableOrder::withoutGlobalScopes()->where('open_table_id', $this->table->id)->sole();

        $this->post($this->url(), [
            'client_uuid' => (string) Str::uuid(),
            'known_order_id' => $old->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(1, TableOrderItem::withoutGlobalScopes()->where('table_order_id', $current->id)->count());
    }

    public function test_codigo_invalido_mesa_inativa_e_empresa_inativa_dao_404(): void
    {
        $this->get('/m/codigo-que-nao-existe')->assertNotFound();

        $this->table->forceFill(['active' => false])->saveQuietly();
        $this->get($this->url())->assertNotFound();

        $this->table->forceFill(['active' => true])->saveQuietly();
        $this->admin->company->forceFill(['active' => false])->saveQuietly();
        $this->get($this->url())->assertNotFound();
        $this->order()->assertNotFound();
    }

    public function test_empresa_pode_desligar_o_cardapio(): void
    {
        $this->admin->company->forceFill(['qr_menu_enabled' => false])->saveQuietly();

        $this->get($this->url())->assertNotFound();
        $this->order()->assertNotFound();
    }

    public function test_funcionario_logado_de_outra_empresa_ve_e_pede_normalmente(): void
    {
        $outsider = $this->adminOf();

        $this->actingAs($outsider)->get($this->url())->assertInertia(fn (Assert $page) => $page->has('products', 1));

        $this->actingAs($outsider)->order()->assertSessionHasNoErrors();

        $order = TableOrder::withoutGlobalScopes()->sole();

        $this->assertSame($this->admin->company_id, $order->company_id);
        $this->assertNull($order->user_id);
        $log = AuditLog::withoutGlobalScopes()->where('auditable_type', 'TableOrderItem')->sole();
        $this->assertSame('Cliente (QR Code)', $log->user_name);
        $this->assertNull($log->user_id);
        $this->assertSame($this->admin->company_id, $log->company_id);
    }

    public function test_numero_da_comanda_e_por_empresa(): void
    {
        $other = Company::factory()->create();
        TableOrder::withoutGlobalScopes()->insert([
            'company_id' => $other->id,
            'number' => 500,
            'dining_table_id' => DiningTable::factory()->create(['company_id' => $other->id])->id,
            'user_name' => 'x',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->order();

        $this->assertSame(1, TableOrder::withoutGlobalScopes()->where('company_id', $this->admin->company_id)->sole()->number);
    }

    public function test_abuso_e_limitado_por_ip(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->post($this->url(), ['client_uuid' => 'x', 'items' => []]);
        }

        $this->post($this->url(), ['client_uuid' => 'x', 'items' => []])->assertTooManyRequests();
    }

    public function test_conta_com_pedido_do_cliente_fecha_normalmente(): void
    {
        $this->order();
        $order = TableOrder::withoutGlobalScopes()->sole();

        $this->openCash($this->admin);

        $this->actingAs($this->admin)->post("/mesas/comandas/{$order->id}/fechar", [
            'client_uuid' => (string) Str::uuid(),
            'expected_subtotal' => '80.00',
            'charge_service' => false,
            'payments' => [['method' => 'pix', 'amount' => '80']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('80.00', Sale::sole()->total);
        $this->assertSame('48.000', $this->product->fresh()->stock_quantity);
    }

    public function test_tela_de_qr_codes_exige_permissao_e_traz_o_link_publico(): void
    {
        $this->actingAs($this->userWith(['tables.operate'], $this->admin->company))->get('/mesas/qrcodes')->assertForbidden();

        $this->actingAs($this->admin)->get('/mesas/qrcodes')->assertInertia(
            fn (Assert $page) => $page->has('tables', 1)->where('tables.0.url', fn ($url) => str_ends_with($url, "/m/{$this->table->qr_token}"))
        );
    }

    public function test_trocar_o_codigo_invalida_o_antigo(): void
    {
        $old = $this->table->qr_token;

        $this->actingAs($this->admin)->post("/mesas/{$this->table->id}/novo-qrcode")->assertSessionHas('success');

        $this->assertNotSame($old, $this->table->fresh()->qr_token);
        $this->get("/m/$old")->assertNotFound();
    }

    public function test_categoria_sem_produto_a_venda_nao_vaza_de_outra_empresa(): void
    {
        Category::factory()->create(['name' => 'De outra empresa']);
        Category::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Pizzas']);

        $this->get($this->url())->assertInertia(fn (Assert $page) => $page->has('categories', 1)->where('categories.0.name', 'Pizzas'));
    }
}
