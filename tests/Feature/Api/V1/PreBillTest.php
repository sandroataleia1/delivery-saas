<?php

namespace Tests\Feature\Api\V1;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Models\DiningTable;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PreBillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $waiter;

    private TableOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->admin = $this->productionAdminOf();
        $admin->company->forceFill(['trade_name' => 'Bar do Zé', 'service_fee_percent' => '10'])->save();
        $this->waiter = $this->userWith(['tables.operate'], $admin->company);
        $this->actingAs($admin);
        $this->order = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $admin->company_id, 'number' => 5]), 2, $admin);
        $burger = Product::factory()->create(['company_id' => $admin->company_id, 'name' => 'X-Burger', 'sale_price' => '30.00']);
        app(AddOrderItems::class)->handle($this->order, [['product_id' => $burger->id, 'quantity' => '2', 'notes' => 'Sem cebola']], (string) Str::uuid());
        PrintJob::query()->delete();
    }

    private function printer(array $attributes = []): Printer
    {
        return Printer::factory()->create(['company_id' => $this->waiter->company_id, ...$attributes]);
    }

    private function prebill()
    {
        Sanctum::actingAs($this->waiter);

        return $this->postJson(route('api.v1.orders.prebill', $this->order));
    }

    public function test_pre_conta_vai_para_a_impressora_da_conta_com_servico(): void
    {
        $orders = $this->printer();
        $bill = $this->printer();
        $this->waiter->company->forceFill(['order_printer_id' => $orders->id, 'bill_printer_id' => $bill->id])->save();

        $this->prebill()->assertStatus(202)->assertExactJson(['queued' => true]);

        $job = PrintJob::sole();
        $this->assertSame($bill->id, $job->printer_id);
        // A impressora térmica recebe o texto sem acentos.
        $ticket = base64_decode($job->payload);
        foreach (['Bar do Ze', 'CONTA - Mesa 5', 'Comanda #'.$this->order->number, 'X-Burger', 'R$ 60,00', 'Servico (10%)', 'R$ 6,00', 'R$ 66,00', 'Nao e documento fiscal'] as $text) {
            $this->assertStringContainsString($text, $ticket);
        }
    }

    public function test_sem_impressora_da_conta_usa_a_de_pedidos(): void
    {
        $orders = $this->printer();
        $this->waiter->company->forceFill(['order_printer_id' => $orders->id])->save();

        $this->prebill()->assertStatus(202);

        $this->assertSame($orders->id, PrintJob::sole()->printer_id);
    }

    public function test_sem_servico_configurado_nao_imprime_linha_de_servico(): void
    {
        $orders = $this->printer();
        $this->waiter->company->forceFill(['order_printer_id' => $orders->id, 'service_fee_percent' => '0'])->save();

        $this->prebill()->assertStatus(202);

        $this->assertStringNotContainsString('Servico', base64_decode(PrintJob::sole()->payload));
    }

    public function test_sem_impressora_ativa_recusa(): void
    {
        $inactive = $this->printer(['active' => false]);
        $this->waiter->company->forceFill(['bill_printer_id' => $inactive->id])->save();

        $this->prebill()->assertUnprocessable()->assertJsonPath('message', 'Não há impressora ativa para a conta. Configure em Impressoras.');

        $this->assertSame(0, PrintJob::count());
    }

    public function test_pre_conta_nao_fecha_a_mesa(): void
    {
        $this->waiter->company->forceFill(['order_printer_id' => $this->printer()->id])->save();

        $this->prebill()->assertStatus(202);

        $this->assertTrue($this->order->fresh()->isOpen());
        $this->assertNull($this->order->fresh()->sale_id);
    }

    public function test_comanda_fechada_ou_vazia_recusa(): void
    {
        $this->waiter->company->forceFill(['order_printer_id' => $this->printer()->id])->save();
        $empty = app(OpenTable::class)->handle(DiningTable::factory()->create(['company_id' => $this->waiter->company_id]), 1, $this->waiter);
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.orders.prebill', $empty))->assertUnprocessable()->assertJsonPath('message', 'Não há itens na conta.');

        $this->order->forceFill(['status' => 'closed', 'open_table_id' => null])->saveQuietly();
        $this->postJson(route('api.v1.orders.prebill', $this->order))->assertUnprocessable()->assertJsonPath('message', 'Esta comanda já foi fechada.');
    }

    public function test_impressoras_salva_a_impressora_da_conta(): void
    {
        $bill = $this->printer();

        $this->actingAs($this->admin)->put(route('printers.destinations'), ['bill_printer_id' => $bill->id])->assertSessionHasNoErrors();

        $this->assertSame($bill->id, $this->waiter->company->fresh()->bill_printer_id);
    }
}
