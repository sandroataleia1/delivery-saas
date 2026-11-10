<?php

namespace Tests\Feature;

use App\Actions\CloseCashSession;
use App\Models\Bill;
use App\Models\BillSettlement;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Bill $bill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminOf();
        $this->actingAs($this->admin);
        $this->bill = Bill::factory()->create(['company_id' => $this->admin->company_id, 'type' => Bill::RECEIVABLE, 'amount' => '80']);
    }

    private function pay(string $amount, array $extra = [])
    {
        return $this->post("/financeiro/contas/{$this->bill->id}/baixa", [
            'paid_at' => today()->format('Y-m-d'), 'paid_amount' => $amount, 'payment_method' => 'pix', ...$extra,
        ]);
    }

    public function test_eighty_is_settled_by_one_then_thirty_then_forty_nine(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
        $this->bill->forceFill(['customer_id' => $customer->id])->save();

        foreach ([['1', '79.00', '1.00', Bill::OPEN], ['30', '49.00', '31.00', Bill::OPEN], ['49', '0.00', '80.00', Bill::PAID]] as [$amount, $balance, $received, $status]) {
            $this->pay($amount)->assertSessionHasNoErrors();
            $this->assertSame($status, $this->bill->fresh()->status);
            $this->assertSame($balance, $this->bill->fresh()->balance());
            $this->assertSame($received, $this->bill->fresh()->paid_amount);
            $this->assertSame($balance, $customer->openCredit());
        }
        $this->assertSame(['1.00', '30.00', '49.00'], BillSettlement::orderBy('id')->pluck('amount')->all());
        $this->assertDatabaseCount('cash_movements', 0);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => 'BillSettlement', 'event' => 'created', 'user_id' => $this->admin->id]);
    }

    public static function invalidAmounts(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'missing' => [null], 'array' => [[]], 'fraction' => ['1.001'], 'text' => ['invalid'], 'over balance' => ['80.01']];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_receipt_creates_no_financial_effect(mixed $amount): void
    {
        $before = $this->bill->fresh()->getRawOriginal();
        $this->post("/financeiro/contas/{$this->bill->id}/baixa", [
            'paid_at' => today()->format('Y-m-d'), 'paid_amount' => $amount, 'payment_method' => 'pix',
        ])->assertSessionHasErrors('paid_amount');

        $this->assertSame($before, $this->bill->fresh()->getRawOriginal());
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_discount_requires_separate_permission(): void
    {
        $manager = $this->userWith(['finance.manage', 'finance.view'], $this->admin->company);
        $this->actingAs($manager);

        $this->pay('70', ['discount_amount' => '10', 'discount_reason' => 'Acordo'])->assertForbidden();

        $this->assertSame(Bill::OPEN, $this->bill->fresh()->status);
        $this->assertDatabaseCount('cash_movements', 0);
    }

    public function test_authorized_discount_is_distinct_from_received_money(): void
    {
        $this->pay('70', ['discount_amount' => '10', 'discount_reason' => 'Acordo autorizado'])->assertSessionHasNoErrors();

        $this->assertSame('70.00', $this->bill->fresh()->paid_amount);
        $this->assertSame('10.00', $this->bill->fresh()->discount_amount);
        $this->assertSame('80.00', $this->bill->fresh()->amount);
        $this->assertSame('0.00', $this->bill->fresh()->balance());
        $this->assertSame(Bill::PAID, $this->bill->fresh()->status);
        $this->assertDatabaseHas('bill_settlements', ['bill_id' => $this->bill->id, 'amount' => '70.00', 'discount_amount' => '10.00', 'discount_reason' => 'Acordo autorizado']);
    }

    public function test_discount_requires_reason_and_must_complete_settlement(): void
    {
        $this->pay('70', ['discount_amount' => '10'])->assertSessionHasErrors('discount_reason');
        $this->pay('10', ['discount_amount' => '10', 'discount_reason' => 'Parcial indevido'])->assertSessionHasErrors('discount_amount');
        $this->assertSame(Bill::OPEN, $this->bill->fresh()->status);
    }

    public function test_partial_cash_receipts_only_move_actual_amount_and_excess_is_atomic(): void
    {
        $this->openCash($this->admin, '0');
        $session = CashSession::where('open_user_id', $this->admin->id)->sole();
        $this->pay('1', ['payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame(Bill::OPEN, $this->bill->fresh()->status);
        $this->pay('80', ['payment_method' => 'cash'])->assertSessionHasErrors('paid_amount');

        $this->assertSame('1.00', $session->calculateSummary()['expected_cash']);
        $this->assertDatabaseCount('cash_movements', 1);
        $this->assertDatabaseCount('bill_settlements', 1);
        $this->assertSame('79.00', $this->bill->fresh()->balance());
    }

    public function test_selected_settlement_is_reversed_without_erasing_other_receipts(): void
    {
        foreach (['1', '30', '49'] as $amount) {
            $this->pay($amount)->assertSessionHasNoErrors();
        }
        $selected = BillSettlement::where('amount', '30')->sole();

        $this->post("/financeiro/contas/{$this->bill->id}/estorno", ['reason' => 'Baixa incorreta', 'settlement_id' => $selected->id])->assertSessionHasNoErrors();

        $this->assertSame('30.00', $this->bill->fresh()->balance());
        $this->assertSame('50.00', $this->bill->fresh()->paid_amount);
        $this->assertSame(Bill::OPEN, $this->bill->fresh()->status);
        $this->assertNotNull($selected->fresh()->reversed_at);
        $this->assertSame(2, BillSettlement::whereNull('reversed_at')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'bill_reopened', 'user_id' => $this->admin->id]);
    }

    public function test_partial_history_prevents_direct_cancellation_and_principal_edit(): void
    {
        $this->pay('1')->assertSessionHasNoErrors();

        $this->post("/financeiro/contas/{$this->bill->id}/cancelar", ['reason' => 'Ocultar saldo'])->assertSessionHasErrors('reason');
        $this->put("/financeiro/contas/{$this->bill->id}", ['description' => 'Outra', 'category' => 'Outros', 'amount' => '1', 'due_date' => today()->format('Y-m-d')])->assertForbidden();

        $this->assertSame('80.00', $this->bill->fresh()->amount);
        $this->assertSame(Bill::OPEN, $this->bill->fresh()->status);
    }

    public function test_reversal_requires_selection_when_several_settlements_exist(): void
    {
        $this->pay('1')->assertSessionHasNoErrors();
        $this->pay('30')->assertSessionHasNoErrors();

        $this->post("/financeiro/contas/{$this->bill->id}/estorno", ['reason' => 'Sem seleção'])->assertSessionHasErrors('settlement_id');

        $this->assertSame('31.00', $this->bill->fresh()->paid_amount);
    }

    public function test_cash_reversal_preserves_previous_closing_snapshot_and_uses_current_cash(): void
    {
        $this->openCash($this->admin, '0');
        $old = CashSession::where('open_user_id', $this->admin->id)->sole();
        $this->pay('1', ['payment_method' => 'cash'])->assertSessionHasNoErrors();
        $selected = $this->bill->settlements()->sole();
        app(CloseCashSession::class)->handle($old, '1', null);
        $snapshot = $old->fresh()->getRawOriginal();
        $this->openCash($this->admin, '10');
        $current = CashSession::where('open_user_id', $this->admin->id)->sole();

        $this->post("/financeiro/contas/{$this->bill->id}/estorno", ['reason' => 'Baixa incorreta', 'settlement_id' => $selected->id])->assertSessionHasNoErrors();

        $this->assertSame($snapshot, $old->fresh()->getRawOriginal());
        $this->assertSame('9.00', $current->calculateSummary()['expected_cash']);
        $this->assertSame('80.00', $this->bill->fresh()->balance());
        $this->assertDatabaseHas('cash_movements', ['id' => $selected->fresh()->reversal_cash_movement_id, 'cash_session_id' => $current->id, 'amount' => '1', 'type' => 'bill_payment']);
    }

    public function test_each_receipt_keeps_its_own_notes_visible_in_history(): void
    {
        $this->pay('1', ['notes' => 'Comprovante A'])->assertSessionHasNoErrors();
        $this->pay('30', ['notes' => 'Comprovante B'])->assertSessionHasNoErrors();

        $this->get("/financeiro/contas/{$this->bill->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('bill.settlements.0.notes', 'Comprovante A')
            ->where('bill.settlements.1.notes', 'Comprovante B'));
        $this->assertDatabaseHas('bill_settlements', ['bill_id' => $this->bill->id, 'amount' => '1', 'notes' => 'Comprovante A']);
        $this->assertDatabaseHas('bill_settlements', ['bill_id' => $this->bill->id, 'amount' => '30', 'notes' => 'Comprovante B']);
    }
}
