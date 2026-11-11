<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryOrder;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\User;
use App\Models\WaiterNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WaiterNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** Usuário com o app instalado: tem permissão e um token do app. */
    private function waiterWithApp(array $permissions, Company $company): User
    {
        $user = $this->userWith($permissions, $company);
        $user->createToken('celular');

        return $user;
    }

    /** @return array{0: Company, 1: DiningTable, 2: array<string, mixed>} */
    private function qrMenu(): array
    {
        $company = Company::factory()->create(['qr_menu_enabled' => true]);
        $table = DiningTable::factory()->create(['company_id' => $company->id, 'number' => 5]);
        $product = Product::factory()->create(['company_id' => $company->id, 'sale_price' => '10', 'stock_quantity' => '10']);

        return [$company, $table, ['service_context' => $table->service_context, 'client_uuid' => (string) Str::uuid(),
            'tracking_key' => str_repeat('a', 64), 'items' => [['product_id' => $product->id, 'quantity' => 2]]]];
    }

    /** Mesa 5 aberta por $opener com X-Burger ×2 e Fritas (Cozinha) e Refri (Bar). */
    private function tableWithKitchenAndBar(User $admin, ?User $opener = null): TableOrder
    {
        $this->actingAs($admin);
        $kitchen = ProductionSector::factory()->create(['company_id' => $admin->company_id, 'name' => 'Cozinha']);
        $bar = ProductionSector::factory()->create(['company_id' => $admin->company_id, 'name' => 'Bar']);
        $admin->company->forceFill(['default_production_sector_id' => $kitchen->id])->save();
        $drinks = Category::factory()->create(['company_id' => $admin->company_id, 'production_sector_id' => $bar->id]);
        $burger = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'X-Burger']);
        $fries = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Fritas']);
        $soda = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Refri', 'category_id' => $drinks->id]);

        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id, 'number' => 5]), 1, $opener ?? $admin);
        app(AddOrderItems::class)->handle($order, [
            ['product_id' => $burger->id, 'quantity' => '2'],
            ['product_id' => $fries->id, 'quantity' => '1'],
            ['product_id' => $soda->id, 'quantity' => '1'],
        ], (string) Str::uuid());

        return $order;
    }

    private function markAllReady(User $admin): void
    {
        $works = ProductionWork::orderBy('id')->get()->map(fn (ProductionWork $work) => ['id' => $work->id, 'version' => $work->version])->all();
        $this->actingAs($admin)->post(route('production.panel.ready'), ['works' => $works])->assertSessionHasNoErrors();
    }

    private function useTableWaiterMode(User $admin): void
    {
        $this->actingAs($admin)->putJson('/empresa/operacao', ['waiter_notices' => 'table_waiter'])->assertSuccessful();
    }

    public function test_pedido_do_qr_avisa_quem_confirma_e_tem_app(): void
    {
        [$company, $table, $data] = $this->qrMenu();
        $waiter = $this->waiterWithApp(['tables.confirm'], $company);
        $this->userWith(['tables.confirm'], $company);
        $this->waiterWithApp(['tables.operate'], $company);
        $inactive = $this->waiterWithApp(['tables.confirm'], $company);
        $inactive->forceFill(['active' => false])->save();

        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();

        $notice = WaiterNotice::withoutGlobalScopes()->sole();
        $this->assertSame($waiter->id, $notice->user_id);
        $this->assertSame('menu_order', $notice->kind);
        $this->assertSame('Mesa 5: pedido pelo QR', $notice->title);
        $this->assertSame('2 itens · R$ 20,00 · aguarda confirmação', $notice->body);
        $this->assertSame($table->id, $notice->dining_table_id);
        $this->assertSame(TableOrder::sole()->id, $notice->table_order_id);
    }

    public function test_reenvio_do_mesmo_pedido_do_qr_nao_duplica_aviso(): void
    {
        [$company, $table, $data] = $this->qrMenu();
        $this->waiterWithApp(['tables.confirm'], $company);

        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();
        $this->postJson('/m/'.$table->qr_token, $data)->assertSuccessful();

        $this->assertSame(1, WaiterNotice::withoutGlobalScopes()->count());
    }

    public function test_pedido_do_qr_confirmado_automaticamente_avisa_sem_pedir_confirmacao(): void
    {
        [$company, $table, $data] = $this->qrMenu();
        $this->waiterWithApp(['tables.confirm'], $company);
        $admin = $this->productionAdminOf($company);
        $this->actingAs($admin)->putJson('/empresa/operacao', ['confirmation_mode' => 'automatic', 'automatic_enabled' => true, 'remote_risk_accepted' => true])->assertOk();
        auth()->logout();

        $this->postJson('/m/'.$table->qr_token, $data)->assertCreated();

        $this->assertSame('2 itens · confirmado automaticamente', WaiterNotice::withoutGlobalScopes()->sole()->body);
    }

    public function test_varios_prontos_na_mesma_requisicao_viram_um_aviso_por_setor(): void
    {
        $admin = $this->adminOf();
        $waiter = $this->waiterWithApp(['production.deliver'], $admin->company);
        $this->tableWithKitchenAndBar($admin);

        $this->markAllReady($admin);

        $notices = WaiterNotice::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertSame([$waiter->id, $waiter->id], $notices->pluck('user_id')->all());
        $this->assertSame(['ready', 'ready'], $notices->pluck('kind')->all());
        $this->assertSame(['Mesa 5: 2 itens prontos — Cozinha', 'Mesa 5: 1 item pronto — Bar'], $notices->pluck('title')->all());
        $this->assertSame(['2× X-Burger, 1× Fritas', '1× Refri'], $notices->pluck('body')->all());
    }

    public function test_item_de_delivery_pronto_nao_avisa_garcom(): void
    {
        $admin = $this->productionAdminOf();
        $this->waiterWithApp(['production.deliver'], $admin->company);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $product = Product::factory()->create(['company_id' => $admin->company_id, 'sale_price' => '30', 'stock_quantity' => '10']);
        $this->openCash($admin);
        $this->actingAs($admin)->post('/entregas', ['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'pickup',
            'items' => [['product_id' => $product->id, 'quantity' => '1']], 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $work = ProductionWork::whereIn('delivery_order_item_id', DeliveryOrder::sole()->items()->pluck('id'))->sole();

        $this->post(route('production.transition', $work->id), ['expected_version' => 0, 'target' => 'preparing'])->assertSessionHasNoErrors();
        $this->post(route('production.transition', $work->id), ['expected_version' => 1, 'target' => 'ready'])->assertSessionHasNoErrors();

        $this->assertSame('ready', $work->fresh()->status);
        $this->assertSame(0, WaiterNotice::withoutGlobalScopes()->count());
    }

    public function test_modo_garcom_da_mesa_avisa_so_o_responsavel(): void
    {
        $admin = $this->adminOf();
        $responsible = $this->waiterWithApp(['production.deliver', 'tables.operate'], $admin->company);
        $this->waiterWithApp(['production.deliver'], $admin->company);
        $this->useTableWaiterMode($admin);
        $this->tableWithKitchenAndBar($admin, $responsible);

        $this->markAllReady($admin);

        $this->assertSame([$responsible->id], WaiterNotice::withoutGlobalScopes()->distinct()->pluck('user_id')->all());
    }

    public function test_modo_garcom_da_mesa_sem_responsavel_com_app_avisa_todos(): void
    {
        $admin = $this->adminOf();
        $responsibleWithoutApp = $this->userWith(['production.deliver', 'tables.operate'], $admin->company);
        $other = $this->waiterWithApp(['production.deliver'], $admin->company);
        $another = $this->waiterWithApp(['production.deliver'], $admin->company);
        $this->useTableWaiterMode($admin);
        $this->tableWithKitchenAndBar($admin, $responsibleWithoutApp);

        $this->markAllReady($admin);

        $this->assertEqualsCanonicalizing([$other->id, $another->id], WaiterNotice::withoutGlobalScopes()->distinct()->pluck('user_id')->all());
    }

    public function test_configuracao_aceita_so_valores_conhecidos(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->putJson('/empresa/operacao', ['waiter_notices' => 'x'])->assertJsonValidationErrors('waiter_notices');
        $this->putJson('/empresa/operacao', ['waiter_notices' => 'table_waiter'])->assertSuccessful();

        $this->assertDatabaseHas('restaurant_operation_settings', ['company_id' => $admin->company_id, 'waiter_notices' => 'table_waiter']);
    }
}
