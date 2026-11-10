<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProductionPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ProductionSector $kitchen;

    private ProductionSector $bar;

    private TableOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->actingAs($this->admin);
        $this->kitchen = ProductionSector::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Cozinha']);
        $this->bar = ProductionSector::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Bar']);
        $this->admin->company->forceFill(['default_production_sector_id' => $this->kitchen->id])->save();
        $drinks = Category::factory()->create(['company_id' => $this->admin->company_id, 'production_sector_id' => $this->bar->id]);

        $burger = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'X-Burger']);
        $fries = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Fritas']);
        $soda = Product::factory()->create(['company_id' => $this->admin->company_id, 'name' => 'Refri', 'category_id' => $drinks->id]);

        $this->order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $this->admin->company_id, 'number' => 7]), 1, $this->admin);
        app(AddOrderItems::class)->handle($this->order, [
            ['product_id' => $burger->id, 'quantity' => '2', 'notes' => 'Sem cebola'],
            ['product_id' => $fries->id, 'quantity' => '1'],
            ['product_id' => $soda->id, 'quantity' => '1'],
        ], (string) Str::uuid());
    }

    public function test_panel_shows_only_the_chosen_sector_grouped_by_order(): void
    {
        $cook = $this->userWith(['production.view', 'production.operate'], $this->admin->company);

        $this->actingAs($cook)->get(route('production.panel'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('production/Panel')->where('sector', null)->has('orders', 0)->has('sectors', 2));

        $this->get(route('production.panel', ['setor' => $this->kitchen->id]))
            ->assertInertia(fn (Assert $page) => $page->where('sector.id', $this->kitchen->id)
                ->has('orders', 1)
                ->where('orders.0.origin', 'Mesa 7 / comanda '.$this->order->number)
                ->has('orders.0.items', 2)
                ->where('orders.0.items.0.product_name', 'X-Burger')
                ->where('orders.0.items.0.notes', 'Sem cebola')
                ->missing('orders.0.items.0.unit_price'));

        $this->get(route('production.panel', ['setor' => $this->bar->id]))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 1)->has('orders.0.items', 1)->where('orders.0.items.0.product_name', 'Refri'));
    }

    public function test_ready_items_leave_the_panel(): void
    {
        $fries = ProductionWork::where('product_name', 'Fritas')->sole();
        $this->post(route('production.transition', $fries->id), ['expected_version' => 0, 'target' => 'preparing']);
        $this->post(route('production.transition', $fries->id), ['expected_version' => 1, 'target' => 'ready']);

        $this->get(route('production.panel', ['setor' => $this->kitchen->id]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.0.items', 1)->where('orders.0.items.0.product_name', 'X-Burger'));
    }

    public function test_whole_order_ready_marks_every_item_of_the_sector(): void
    {
        $kitchenWorks = ProductionWork::where('production_sector_id', $this->kitchen->id)->orderBy('id')->get();
        $this->post(route('production.transition', $kitchenWorks[0]->id), ['expected_version' => 0, 'target' => 'preparing']);

        $this->post(route('production.panel.ready'), ['works' => [
            ['id' => $kitchenWorks[0]->id, 'version' => 1],
            ['id' => $kitchenWorks[1]->id, 'version' => 0],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(['ready', 'ready'], $kitchenWorks->map(fn (ProductionWork $work) => $work->fresh()->status)->all());
        $this->assertSame('waiting', ProductionWork::where('production_sector_id', $this->bar->id)->sole()->status);
    }

    public function test_whole_order_ready_is_all_or_nothing_when_a_line_changed(): void
    {
        $kitchenWorks = ProductionWork::where('production_sector_id', $this->kitchen->id)->orderBy('id')->get();

        $this->post(route('production.panel.ready'), ['works' => [
            ['id' => $kitchenWorks[0]->id, 'version' => 0],
            ['id' => $kitchenWorks[1]->id, 'version' => 5],
        ]])->assertSessionHasErrors('production');

        $this->assertSame(['waiting', 'waiting'], $kitchenWorks->map(fn (ProductionWork $work) => $work->fresh()->status)->all());
    }

    public function test_panel_needs_permission_and_stays_in_the_company(): void
    {
        $work = ProductionWork::first();
        $this->actingAs($this->userWith([], $this->admin->company))->get(route('production.panel'))->assertForbidden();
        $this->actingAs($this->userWith(['production.view'], $this->admin->company))
            ->post(route('production.panel.ready'), ['works' => [['id' => $work->id, 'version' => 0]]])->assertForbidden();

        $foreign = $this->adminOf();
        $this->actingAs($foreign)->get(route('production.panel', ['setor' => $this->kitchen->id]))->assertSessionHasErrors('setor');
        $this->post(route('production.panel.ready'), ['works' => [['id' => $work->id, 'version' => 0]]])->assertSessionHasErrors('works.0.id');
        $this->assertSame('waiting', ProductionWork::withoutGlobalScopes()->find($work->id)->status);
    }

    public function test_quem_so_acessa_a_cozinha_entra_direto_no_painel(): void
    {
        $cook = $this->userWith(['production.view', 'production.operate'], $this->admin->company);

        $this->actingAs($cook)->get(route('dashboard'))->assertRedirect(route('production.panel'));
    }

    public function test_quem_tem_outras_areas_continua_no_inicio(): void
    {
        $waiter = $this->userWith(['production.view', 'tables.operate'], $this->admin->company);

        $this->actingAs($waiter)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
    }
}
