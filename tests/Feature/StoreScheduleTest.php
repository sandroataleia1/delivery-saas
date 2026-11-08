<?php

namespace Tests\Feature;

use App\Models\OnlineStore;
use App\Models\OnlineStoreHour;
use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<int, array{0: int, 1: string, 2: string}> $shifts */
    private function storeWith(array $shifts, bool $enabled = true): OnlineStore
    {
        $store = OnlineStore::factory()->create(['enabled' => $enabled]);

        foreach ($shifts as [$weekday, $opens, $closes]) {
            (new OnlineStoreHour)->forceFill([
                'company_id' => $store->company_id,
                'weekday' => $weekday,
                'opens_at' => $opens,
                'closes_at' => $closes,
            ])->save();
        }

        return $store->fresh();
    }

    private function at(string $local): CarbonImmutable
    {
        return CarbonImmutable::parse($local, 'America/Sao_Paulo');
    }

    public function test_overnight_shift_spans_midnight(): void
    {
        $store = $this->storeWith([[6, '18:00:00', '02:00:00']]);

        $this->assertFalse(StoreSchedule::isOpen($store, $this->at('2026-11-07 17:59')));
        $this->assertTrue(StoreSchedule::isOpen($store, $this->at('2026-11-07 18:00')));
        $this->assertTrue(StoreSchedule::isOpen($store, $this->at('2026-11-08 01:30')));
        $this->assertFalse(StoreSchedule::isOpen($store, $this->at('2026-11-08 02:00')));
    }

    public function test_label_next_opening(): void
    {
        $store = $this->storeWith([[5, '18:00:00', '22:00:00'], [6, '18:30:00', '23:00:00']]);
        $this->assertSame('Fechado · abre amanhã às 18h30', StoreSchedule::label($store, $this->at('2026-11-06 23:00')));
        $this->assertSame('Aberto', StoreSchedule::label($store, $this->at('2026-11-06 19:00')));
        $this->assertSame('Fechado · abre sexta às 18h', StoreSchedule::label($store, $this->at('2026-11-02 10:00')));

        $tuesday = $this->storeWith([[2, '18:00:00', '23:00:00']]);
        $this->assertSame('Fechado · abre hoje às 18h', StoreSchedule::label($tuesday, $this->at('2026-11-03 10:00')));
    }

    public function test_label_converts_to_sao_paulo_timezone(): void
    {
        $store = $this->storeWith([[2, '18:00:00', '23:00:00']]);

        $this->assertSame('Fechado · abre hoje às 18h', StoreSchedule::label($store, CarbonImmutable::parse('2026-11-03 13:00', 'UTC')));
    }

    public function test_pause_closes_over_schedule_until_resumed(): void
    {
        $admin = $this->adminOf();
        $store = OnlineStore::factory()->create(['company_id' => $admin->company_id, 'enabled' => true]);
        (new OnlineStoreHour)->forceFill(['company_id' => $admin->company_id, 'weekday' => 2, 'opens_at' => '08:00:00', 'closes_at' => '23:00:00'])->save();
        $now = $this->at('2026-11-03 12:00');

        $this->assertTrue(StoreSchedule::isOpen($store->fresh(), $now));

        $this->actingAs($admin)->post('/loja-online/pausa', ['reason' => 'Forno quebrou'])->assertRedirect();
        $paused = $store->fresh();
        $this->assertNotNull($paused->paused_at);
        $this->assertSame($admin->name, $paused->paused_by_name);
        $this->assertSame('Forno quebrou', $paused->paused_reason);
        $this->assertFalse(StoreSchedule::isOpen($paused, $now));
        $this->assertSame('Fechado no momento', StoreSchedule::label($paused, $now));
        $this->assertDatabaseHas('audit_logs', ['company_id' => $admin->company_id, 'event' => 'paused']);

        $this->delete('/loja-online/pausa')->assertRedirect();
        $resumed = $store->fresh();
        $this->assertNull($resumed->paused_at);
        $this->assertNull($resumed->paused_by_name);
        $this->assertNull($resumed->paused_reason);
        $this->assertTrue(StoreSchedule::isOpen($resumed, $now));
        $this->assertDatabaseHas('audit_logs', ['company_id' => $admin->company_id, 'event' => 'resumed']);
    }

    public function test_disabled_store_is_closed(): void
    {
        $store = $this->storeWith([[2, '08:00:00', '23:00:00']], enabled: false);

        $this->assertFalse(StoreSchedule::isOpen($store, $this->at('2026-11-03 12:00')));
    }

    public function test_pause_requires_accept_permission(): void
    {
        $courier = $this->userWith(['deliveries.courier']);
        OnlineStore::factory()->create(['company_id' => $courier->company_id, 'enabled' => true]);

        $this->actingAs($courier)->post('/loja-online/pausa')->assertForbidden();
        $this->delete('/loja-online/pausa')->assertForbidden();

        $waiter = $this->userWith(['online_orders.accept']);
        OnlineStore::factory()->create(['company_id' => $waiter->company_id, 'enabled' => true]);
        $this->actingAs($waiter)->post('/loja-online/pausa')->assertRedirect();
    }
}
