<?php

namespace Tests\Feature;

use App\Actions\ExpireOnlineOrders;
use App\Models\AuditLog;
use App\Models\OnlineOrder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnlineOrderExpireTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    private function makeOrder(int $companyId, string $status, CarbonImmutable $expiresAt): OnlineOrder
    {
        $this->sequence++;
        $order = new OnlineOrder;
        $order->forceFill([
            'company_id' => $companyId,
            'number' => $this->sequence,
            'client_uuid' => (string) Str::uuid(),
            'content_hash' => hash('sha256', (string) $this->sequence),
            'tracking_token_hash' => hash('sha256', Str::random(48)),
            'tracking_token' => Str::random(48),
            'status' => $status,
            'type' => 'pickup',
            'customer_name' => 'Joana',
            'customer_phone' => '11988887777',
            'subtotal' => '10.00',
            'delivery_fee' => '0.00',
            'total' => '10.00',
            'payment_method' => 'pix',
            'expires_at' => $expiresAt,
        ])->save();

        return $order;
    }

    public function test_command_expires_only_overdue_pending(): void
    {
        $companyId = $this->adminOf()->company_id;
        $now = CarbonImmutable::now();
        $overdue = $this->makeOrder($companyId, OnlineOrder::PENDING, $now->subMinute());
        $overdueAwaiting = $this->makeOrder($companyId, OnlineOrder::AWAITING_PAYMENT, $now->subMinute());
        $onTime = $this->makeOrder($companyId, OnlineOrder::PENDING, $now->addMinutes(5));
        $accepted = $this->makeOrder($companyId, OnlineOrder::ACCEPTED, $now->subHour());

        $this->artisan('online-orders:expire')->assertSuccessful();

        $overdue->refresh();
        $this->assertSame(OnlineOrder::EXPIRED, $overdue->status);
        $this->assertNotNull($overdue->decided_at);
        $this->assertSame('Prazo de aceite encerrado.', $overdue->decision_reason);
        $this->assertSame(OnlineOrder::EXPIRED, $overdueAwaiting->refresh()->status);
        $this->assertSame(OnlineOrder::PENDING, $onTime->refresh()->status);
        $this->assertSame(OnlineOrder::ACCEPTED, $accepted->refresh()->status);

        $log = AuditLog::where('auditable_type', 'OnlineOrder')->where('auditable_id', $overdue->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(ExpireOnlineOrders::ACTOR, $log->user_name);
        $this->assertSame($companyId, (int) $log->company_id);
    }

    public function test_expire_skips_order_accepted_meanwhile(): void
    {
        $companyId = $this->adminOf()->company_id;
        $now = CarbonImmutable::now();
        $order = $this->makeOrder($companyId, OnlineOrder::ACCEPTED, $now->subMinute());

        $expired = app(ExpireOnlineOrders::class)->expireOne($order->id, $companyId, $now);

        $this->assertFalse($expired);
        $this->assertSame(OnlineOrder::ACCEPTED, $order->refresh()->status);
        $this->assertNull($order->decision_reason);
    }

    public function test_schedule_registers_command(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $event): bool => str_contains($event->command, 'online-orders:expire'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
