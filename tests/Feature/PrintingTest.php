<?php

namespace Tests\Feature;

use App\Actions\AddOrderItems;
use App\Actions\CreateDeliveryOrder;
use App\Actions\OpenTable;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PrintingTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_added_together_print_one_kitchen_ticket_per_sector(): void
    {
        [$admin, $sector] = $this->restaurant();
        $printer = Printer::factory()->create(['company_id' => $admin->company_id]);
        $sector->forceFill(['printer_id' => $printer->id])->save();
        $burger = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'X-Burguer Ação']);
        $fries = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Batata']);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);

        app(AddOrderItems::class)->handle($order, [
            ['product_id' => $burger->id, 'quantity' => '2', 'notes' => 'Sem cebola'],
            ['product_id' => $fries->id, 'quantity' => '1'],
        ], (string) Str::uuid());

        $job = PrintJob::sole();
        $this->assertSame($printer->id, $job->printer_id);
        $this->assertSame(PrintJob::PENDING, $job->status);
        $ticket = base64_decode($job->payload);
        $this->assertStringContainsString('X-Burguer Acao', $ticket);
        $this->assertStringContainsString('Batata', $ticket);
        $this->assertStringContainsString('Sem cebola', $ticket);
        $this->assertTrue(mb_check_encoding($ticket, 'ASCII'));
    }

    public function test_sector_without_printer_prints_nothing(): void
    {
        [$admin] = $this->restaurant();
        Printer::factory()->create(['company_id' => $admin->company_id]);
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);

        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], (string) Str::uuid());

        $this->assertSame(0, PrintJob::count());
    }

    public function test_rolled_back_items_never_print(): void
    {
        [$admin, $sector] = $this->restaurant();
        $printer = Printer::factory()->create(['company_id' => $admin->company_id]);
        $sector->forceFill(['printer_id' => $printer->id])->save();
        $lost = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Pedido Desfeito']);
        $kept = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Pedido Gravado']);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);

        try {
            DB::transaction(function () use ($order, $lost) {
                app(AddOrderItems::class)->handle($order, [['product_id' => $lost->id, 'quantity' => '1']], (string) Str::uuid());
                throw new RuntimeException('falhou depois');
            });
        } catch (RuntimeException) {
        }
        app(AddOrderItems::class)->handle($order, [['product_id' => $kept->id, 'quantity' => '1']], (string) Str::uuid());

        $ticket = base64_decode(PrintJob::sole()->payload);
        $this->assertStringContainsString('Pedido Gravado', $ticket);
        $this->assertStringNotContainsString('Pedido Desfeito', $ticket);
    }

    public function test_delivery_order_prints_on_order_printer(): void
    {
        [$admin] = $this->restaurant();
        $printer = Printer::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['order_printer_id' => $printer->id])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id, 'name' => 'Joana']);

        $order = app(CreateDeliveryOrder::class)->handle(['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'pickup', 'payment_method' => 'pix', 'items' => [['product_id' => $product->id, 'quantity' => '2']]]);

        $job = PrintJob::sole();
        $this->assertSame($printer->id, $job->printer_id);
        $this->assertSame('Pedido #'.$order->number, $job->title);
        $this->assertStringContainsString('Joana', base64_decode($job->payload));
    }

    public function test_delivery_reprint_requires_an_active_order_printer(): void
    {
        [$admin] = $this->restaurant();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $order = app(CreateDeliveryOrder::class)->handle(['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'pickup', 'payment_method' => 'pix', 'items' => [['product_id' => $product->id, 'quantity' => '1']]]);

        $this->post(route('deliveries.print', $order))->assertSessionHasErrors('print');
        $this->assertSame(0, PrintJob::count());

        $printer = Printer::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['order_printer_id' => $printer->id])->save();
        $this->post(route('deliveries.print', $order))->assertSessionHasNoErrors();
        $this->assertSame(1, PrintJob::count());
    }

    public function test_agent_rejects_unknown_token(): void
    {
        $this->getJson('/print-agent/jobs', ['Authorization' => 'Bearer errado'])->assertUnauthorized();
        $this->getJson('/print-agent/jobs')->assertUnauthorized();
    }

    public function test_agent_receives_only_own_pending_jobs_and_claims_them(): void
    {
        $company = Company::factory()->create();
        $token = $this->installAgent($company);
        $mine = $this->job($company);
        $printed = $this->job($company, ['status' => PrintJob::PRINTED]);
        $other = $this->job(Company::factory()->create());

        $response = $this->getJson('/print-agent/jobs', ['Authorization' => 'Bearer '.$token])->assertOk();

        $this->assertSame([$mine->id], array_column($response->json('jobs'), 'id'));
        $response->assertJsonPath('jobs.0.printer.connection', Printer::NETWORK);
        $this->assertNotNull($mine->fresh()->claimed_at);
        $this->assertNotNull($company->fresh()->print_agent_seen_at);
        $this->assertSame([], $this->getJson('/print-agent/jobs', ['Authorization' => 'Bearer '.$token])->json('jobs'));

        $this->travel(61)->seconds();
        $this->assertSame([$mine->id], array_column($this->getJson('/print-agent/jobs', ['Authorization' => 'Bearer '.$token])->json('jobs'), 'id'));
        $this->assertSame(PrintJob::PRINTED, $printed->fresh()->status);
        $this->assertNull($other->fresh()->claimed_at);
    }

    public function test_agent_reports_printed_and_failed_jobs(): void
    {
        $company = Company::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$this->installAgent($company)];
        $ok = $this->job($company);
        $broken = $this->job($company, ['attempts' => PrintJob::MAX_ATTEMPTS - 2]);

        $this->postJson("/print-agent/jobs/{$ok->id}/printed", [], $headers)->assertOk();
        $this->assertSame(PrintJob::PRINTED, $ok->fresh()->status);
        $this->assertNotNull($ok->fresh()->printed_at);
        $this->postJson("/print-agent/jobs/{$ok->id}/printed", [], $headers)->assertNotFound();

        $this->postJson("/print-agent/jobs/{$broken->id}/failed", ['error' => 'Impressora sem papel'], $headers)->assertOk();
        $this->assertSame(PrintJob::PENDING, $broken->fresh()->status);
        $this->assertSame('Impressora sem papel', $broken->fresh()->error);
        $this->postJson("/print-agent/jobs/{$broken->id}/failed", ['error' => 'Impressora sem papel'], $headers)->assertOk();
        $this->assertSame(PrintJob::FAILED, $broken->fresh()->status);
    }

    public function test_agent_cannot_touch_another_company_job(): void
    {
        $headers = ['Authorization' => 'Bearer '.$this->installAgent(Company::factory()->create())];
        $other = $this->job(Company::factory()->create());

        $this->postJson("/print-agent/jobs/{$other->id}/printed", [], $headers)->assertNotFound();
        $this->postJson("/print-agent/jobs/{$other->id}/failed", ['error' => 'x'], $headers)->assertNotFound();
        $this->assertSame(PrintJob::PENDING, $other->fresh()->status);
    }

    public function test_downloading_agent_embeds_new_token_and_revokes_old_one(): void
    {
        $admin = $this->adminOf();
        $old = $this->installAgent($admin->company);

        $script = $this->actingAs($admin)->get(route('printers.agent'))->assertOk()->getContent();

        $this->assertStringContainsString("\$Server = '".url('/')."'", $script);
        $this->assertMatchesRegularExpression("/\\\$Token = '([A-Za-z0-9]{48})'/", $script);
        preg_match("/\\\$Token = '([A-Za-z0-9]{48})'/", $script, $match);
        $this->getJson('/print-agent/jobs', ['Authorization' => 'Bearer '.$old])->assertUnauthorized();
        $this->getJson('/print-agent/jobs', ['Authorization' => 'Bearer '.$match[1]])->assertOk();
    }

    public function test_printer_settings_require_company_manage(): void
    {
        $user = $this->userWith(['tables.operate']);

        $this->actingAs($user)->get(route('printers.index'))->assertForbidden();
        $this->actingAs($user)->get(route('printers.agent'))->assertForbidden();
        $this->actingAs($user)->post(route('printers.store'), $this->printerData())->assertForbidden();
        $this->assertSame(0, Printer::withoutGlobalScopes()->count());
    }

    public function test_admin_registers_printers_and_validation_rejects_bad_addresses(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);

        $this->post(route('printers.store'), $this->printerData())->assertSessionHasNoErrors();
        $this->post(route('printers.store'), $this->printerData(['name' => 'Balcão', 'connection' => Printer::WINDOWS, 'address' => '\\\\CAIXA\\Balcao', 'port' => 9100]))->assertSessionHasNoErrors();
        $this->post(route('printers.store'), $this->printerData(['name' => 'Outra', 'address' => 'http://192.168.0.9:9100']))->assertSessionHasErrors('address');
        $this->post(route('printers.store'), $this->printerData(['name' => 'Sem porta', 'port' => null]))->assertSessionHasErrors('port');
        $this->post(route('printers.store'), $this->printerData())->assertSessionHasErrors('name');

        $this->assertSame(['192.168.0.50', '\\\\CAIXA\\Balcao'], Printer::orderBy('id')->pluck('address')->all());
        $this->assertNull(Printer::where('connection', Printer::WINDOWS)->value('port'));
    }

    public function test_manual_kitchen_mode_prints_nothing_on_launch(): void
    {
        [$admin, $sector] = $this->restaurant();
        $sector->forceFill(['printer_id' => Printer::factory()->create(['company_id' => $admin->company_id])->id])->save();
        $admin->company->forceFill(['auto_print_kitchen' => false])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);

        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], (string) Str::uuid());

        $this->assertSame(1, ProductionWork::count());
        $this->assertSame(0, PrintJob::count());
    }

    public function test_manual_order_mode_prints_nothing_on_creation_but_button_still_prints(): void
    {
        [$admin] = $this->restaurant();
        $printer = Printer::factory()->create(['company_id' => $admin->company_id]);
        $admin->company->forceFill(['order_printer_id' => $printer->id, 'auto_print_orders' => false])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);

        $order = app(CreateDeliveryOrder::class)->handle(['client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'type' => 'pickup', 'payment_method' => 'pix', 'items' => [['product_id' => $product->id, 'quantity' => '1']]]);
        $this->assertSame(0, PrintJob::count());

        $this->post(route('deliveries.print', $order))->assertSessionHasNoErrors();
        $this->assertSame(1, PrintJob::count());
    }

    public function test_production_panel_prints_the_ticket_of_the_item_origin_and_sector(): void
    {
        [$admin, $sector] = $this->restaurant();
        $printer = Printer::factory()->create(['company_id' => $admin->company_id]);
        $sector->forceFill(['printer_id' => $printer->id])->save();
        $admin->company->forceFill(['auto_print_kitchen' => false])->save();
        $burger = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Hamburguer']);
        $fries = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Batata']);
        $other = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Outra Mesa']);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        $otherOrder = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        app(AddOrderItems::class)->handle($order, [['product_id' => $burger->id, 'quantity' => '1'], ['product_id' => $fries->id, 'quantity' => '1']], (string) Str::uuid());
        app(AddOrderItems::class)->handle($otherOrder, [['product_id' => $other->id, 'quantity' => '1']], (string) Str::uuid());
        $work = ProductionWork::where('product_name', 'Hamburguer')->sole();

        $this->actingAs($this->userWith(['production.view'], $admin->company))->post(route('production.print', $work))->assertSessionHasNoErrors();

        $job = PrintJob::sole();
        $ticket = base64_decode($job->payload);
        $this->assertSame($printer->id, $job->printer_id);
        $this->assertStringContainsString('Hamburguer', $ticket);
        $this->assertStringContainsString('Batata', $ticket);
        $this->assertStringNotContainsString('Outra Mesa', $ticket);
    }

    public function test_manual_kitchen_print_requires_a_printer_and_stays_in_the_company(): void
    {
        [$admin] = $this->restaurant();
        $admin->company->forceFill(['auto_print_kitchen' => false])->save();
        $product = Product::factory()->create(['company_id' => $admin->company_id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        app(AddOrderItems::class)->handle($order, [['product_id' => $product->id, 'quantity' => '1']], (string) Str::uuid());
        $work = ProductionWork::sole();

        $this->post(route('production.print', $work))->assertSessionHasErrors('print');
        $this->post(route('orders.print', $order))->assertSessionHasErrors('print');

        $this->actingAs($this->productionAdminOf());
        $this->post(route('production.print', $work))->assertNotFound();
        $this->post(route('orders.print', $order))->assertNotFound();
        $this->actingAs($this->userWith([], $admin->company))->post(route('production.print', $work))->assertForbidden();
        $this->assertSame(0, PrintJob::count());
    }

    public function test_table_order_prints_open_items_one_ticket_per_sector(): void
    {
        [$admin, $kitchen] = $this->restaurant();
        $kitchenPrinter = Printer::factory()->create(['company_id' => $admin->company_id]);
        $barPrinter = Printer::factory()->create(['company_id' => $admin->company_id]);
        $kitchen->forceFill(['printer_id' => $kitchenPrinter->id])->save();
        $bar = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $bar->forceFill(['printer_id' => $barPrinter->id])->save();
        $drinks = Category::factory()->create(['company_id' => $admin->company_id, 'production_sector_id' => $bar->id]);
        $admin->company->forceFill(['auto_print_kitchen' => false])->save();
        $burger = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Hamburguer']);
        $soda = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'Refrigerante', 'category_id' => $drinks->id]);
        $order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id]), 1, $admin);
        app(AddOrderItems::class)->handle($order, [['product_id' => $burger->id, 'quantity' => '1'], ['product_id' => $soda->id, 'quantity' => '1']], (string) Str::uuid());

        $this->post(route('orders.print', $order))->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing([$kitchenPrinter->id, $barPrinter->id], PrintJob::pluck('printer_id')->all());
    }

    public function test_print_modes_are_saved_with_destinations(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);

        $this->put(route('printers.destinations'), ['auto_print_orders' => false, 'auto_print_kitchen' => true])->assertSessionHasNoErrors();

        $company = $admin->company->fresh();
        $this->assertFalse($company->auto_print_orders);
        $this->assertTrue($company->auto_print_kitchen);
    }

    public function test_destinations_and_actions_stay_inside_the_company(): void
    {
        $admin = $this->adminOf();
        $sector = ProductionSector::factory()->create(['company_id' => $admin->company_id]);
        $foreign = Printer::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->actingAs($admin);

        $this->put(route('printers.destinations'), ['order_printer_id' => $foreign->id, 'sectors' => [$sector->id => $foreign->id]])->assertSessionHasErrors(['order_printer_id', "sectors.{$sector->id}"]);
        $this->post(route('printers.test', $foreign))->assertNotFound();
        $this->delete(route('printers.destroy', $foreign))->assertNotFound();

        $mine = Printer::factory()->create(['company_id' => $admin->company_id]);
        $this->put(route('printers.destinations'), ['order_printer_id' => $mine->id, 'sectors' => [$sector->id => $mine->id]])->assertSessionHasNoErrors();
        $this->assertSame($mine->id, $admin->company->fresh()->order_printer_id);
        $this->assertSame($mine->id, $sector->fresh()->printer_id);

        $this->post(route('printers.test', $mine))->assertSessionHasNoErrors();
        $this->assertSame('Teste de impressão', PrintJob::sole()->title);
    }

    public function test_failed_job_can_be_sent_again(): void
    {
        $admin = $this->adminOf();
        $job = $this->job($admin->company, ['status' => PrintJob::FAILED, 'attempts' => PrintJob::MAX_ATTEMPTS, 'error' => 'Sem papel']);

        $this->actingAs($admin)->post(route('printers.retry', $job))->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame(PrintJob::PENDING, $job->status);
        $this->assertSame(0, $job->attempts);
        $this->assertNull($job->error);
    }

    /** @return array{0: User, 1: ProductionSector} */
    private function restaurant(): array
    {
        $admin = $this->productionAdminOf();
        $this->actingAs($admin);

        return [$admin, ProductionSector::findOrFail($admin->company->default_production_sector_id)];
    }

    private function installAgent(Company $company): string
    {
        $token = Str::random(48);
        $company->forceFill(['print_agent_token' => hash('sha256', $token)])->saveQuietly();

        return $token;
    }

    /** @param array<string, mixed> $attributes */
    private function job(Company $company, array $attributes = []): PrintJob
    {
        $printer = Printer::factory()->create(['company_id' => $company->id]);
        $job = new PrintJob;
        $job->forceFill([
            'company_id' => $company->id,
            'printer_id' => $printer->id,
            'title' => 'Comanda',
            'payload' => base64_encode('teste'),
            'status' => PrintJob::PENDING,
            ...$attributes,
        ])->save();

        return $job;
    }

    /** @param array<string, mixed> $overrides */
    private function printerData(array $overrides = []): array
    {
        return [
            'name' => 'Cozinha',
            'connection' => Printer::NETWORK,
            'address' => '192.168.0.50',
            'port' => 9100,
            'columns' => 48,
            'active' => true,
            ...$overrides,
        ];
    }
}
