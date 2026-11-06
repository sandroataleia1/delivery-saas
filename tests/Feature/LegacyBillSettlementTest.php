<?php

namespace Tests\Feature;

use App\Actions\RecordCashMovement;
use App\Models\Bill;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyBillSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_checks_cash_ownership_amount_and_direction_and_is_idempotent(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $this->openCash($admin, '100');
        $session = CashSession::where('open_user_id', $admin->id)->sole();
        $movement = app(RecordCashMovement::class)->handle($session, CashMovement::BILL_RECEIPT, '80', 'Legado');
        $attributes = ['company_id' => $admin->company_id, 'type' => Bill::RECEIVABLE, 'amount' => '80', 'status' => Bill::PAID,
            'paid_amount' => '80', 'paid_at' => today(), 'payment_method' => 'cash', 'cash_movement_id' => $movement->id];
        $valid = Bill::factory()->create($attributes);
        $wrongAmount = Bill::factory()->create([...$attributes, 'amount' => '70', 'paid_amount' => '70']);
        $wrongDirection = Bill::factory()->create([...$attributes, 'type' => Bill::PAYABLE]);
        $foreign = Bill::factory()->create([...$attributes, 'company_id' => $this->adminOf()->company_id]);
        $nonCash = Bill::factory()->create([...$attributes, 'payment_method' => 'pix']);
        $before = $session->calculateSummary();
        $migration = require database_path('migrations/2026_11_07_100004_create_bill_settlements_table.php');

        $migration->importLegacySettlements();
        $migration->importLegacySettlements();

        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseHas('bill_settlements', ['bill_id' => $valid->id, 'cash_movement_id' => $movement->id, 'amount' => '80']);
        foreach ([$wrongAmount, $wrongDirection, $foreign, $nonCash] as $bill) {
            $this->assertTrue($bill->fresh()->legacy_review_required);
            $this->assertDatabaseMissing('bill_settlements', ['bill_id' => $bill->id]);
        }
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertSame($before, $session->calculateSummary());
    }

    public static function nonCashMethods(): array
    {
        return [['pix'], ['transfer'], ['boleto'], ['credit'], ['debit']];
    }

    #[DataProvider('nonCashMethods')]
    public function test_import_supports_each_existing_payment_method(string $method): void
    {
        $admin = $this->adminOf();
        $bill = Bill::factory()->create(['company_id' => $admin->company_id, 'status' => Bill::PAID, 'paid_amount' => '100', 'paid_at' => today(), 'payment_method' => $method]);
        $migration = require database_path('migrations/2026_11_07_100004_create_bill_settlements_table.php');

        $migration->importLegacySettlements();

        $this->assertDatabaseHas('bill_settlements', ['bill_id' => $bill->id, 'payment_method' => $method]);
        $this->assertFalse($bill->fresh()->legacy_review_required);
    }

    public function test_import_preserves_exact_legacy_receipt_and_marks_unexplained_differences_without_inventing_discount(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $customer = Customer::factory()->create(['company_id' => $admin->company_id]);
        $data = ['company_id' => $admin->company_id, 'type' => Bill::RECEIVABLE, 'amount' => '80', 'status' => Bill::PAID,
            'paid_at' => '2026-10-01', 'payment_method' => 'pix', 'settled_by' => 'Responsável antigo'];
        $coherent = Bill::factory()->create([...$data, 'paid_amount' => '80']);
        $underpaid = Bill::factory()->create([...$data, 'paid_amount' => '1']);
        $overpaid = Bill::factory()->create([...$data, 'paid_amount' => '100']);
        $incomplete = Bill::factory()->create([...$data, 'paid_amount' => null]);
        $open = Bill::factory()->create([...$data, 'customer_id' => $customer->id, 'status' => Bill::OPEN, 'paid_amount' => '1']);
        $originals = DB::table('bills')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $auditBefore = DB::table('audit_logs')->count();

        $migration = require database_path('migrations/2026_11_07_100004_create_bill_settlements_table.php');
        $migration->importLegacySettlements();

        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertDatabaseHas('bill_settlements', ['bill_id' => $coherent->id, 'amount' => '80.00', 'discount_amount' => '0.00',
            'paid_at' => '2026-10-01', 'user_name' => 'Responsável antigo', 'user_id' => null, 'legacy_imported' => true]);
        foreach ([$underpaid, $overpaid, $incomplete, $open] as $bill) {
            $this->assertTrue($bill->fresh()->legacy_review_required);
            $this->assertSame('0.00', $bill->fresh()->discount_amount);
            $this->assertDatabaseMissing('bill_settlements', ['bill_id' => $bill->id]);
        }
        $current = DB::table('bills')->orderBy('id')->get()->map(function ($row) {
            $data = (array) $row;
            $data['legacy_review_required'] = 0;

            return $data;
        })->all();
        $this->assertSame($originals, $current);
        $this->assertSame($auditBefore, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertSame('80.00', $customer->openCredit());
        $this->post("/financeiro/contas/{$underpaid->id}/estorno", ['reason' => 'Sem reconciliação'])->assertSessionHasErrors('reason');
        $this->post("/financeiro/contas/{$open->id}/baixa", ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => '1', 'payment_method' => 'pix'])->assertSessionHasErrors('bill');
    }

    public function test_rollback_refuses_to_destroy_recorded_history(): void
    {
        $admin = $this->adminOf();
        $this->actingAs($admin);
        $bill = Bill::factory()->create(['company_id' => $admin->company_id, 'amount' => '80']);
        $this->post("/financeiro/contas/{$bill->id}/baixa", ['paid_at' => today()->format('Y-m-d'), 'paid_amount' => '1', 'payment_method' => 'pix'])->assertSessionHasNoErrors();
        $migration = require database_path('migrations/2026_11_07_100004_create_bill_settlements_table.php');

        try {
            $migration->down();
            $this->fail('Rollback não deve apagar histórico financeiro.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('histórico financeiro', $exception->getMessage());
        }
        $this->assertDatabaseCount('bill_settlements', 1);
    }
}
