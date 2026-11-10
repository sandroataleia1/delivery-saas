<?php

namespace Tests\Feature\Api\V1;

use App\Actions\OpenTable;
use App\Models\DiningTable;
use App\Models\MenuOrderReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TableTest extends TestCase
{
    use RefreshDatabase;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->waiter = $this->userWith(['tables.operate'], $this->adminOf()->company);
    }

    private function table(int $number): DiningTable
    {
        return DiningTable::factory()->create(['company_id' => $this->waiter->company_id, 'number' => $number]);
    }

    public function test_lista_as_mesas_da_empresa_com_o_status(): void
    {
        $this->table(1);
        $open = app(OpenTable::class)->handle($this->table(2), 3, $this->waiter, $this->waiter->name);
        app(OpenTable::class)->handle($this->table(3), 2, $this->waiter, $this->waiter->name)
            ->forceFill(['bill_requested_at' => now()])->save();
        MenuOrderReceipt::factory()->create(['dining_table_id' => $this->table(4)->id]);

        Sanctum::actingAs($this->waiter);

        $this->getJson('/api/v1/tables')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.0.status', 'free')
            ->assertJsonPath('data.0.order', null)
            ->assertJsonPath('data.1.status', 'open')
            ->assertJsonPath('data.1.order.id', $open->id)
            ->assertJsonPath('data.1.order.people', 3)
            ->assertJsonPath('data.1.order.waiter', $this->waiter->name)
            ->assertJsonPath('data.2.status', 'closing')
            ->assertJsonPath('data.3.status', 'pending')
            ->assertJsonPath('data.3.order.pending_count', 1);
    }

    public function test_nao_ve_mesas_de_outra_empresa(): void
    {
        $this->table(1);
        DiningTable::factory()->create(['number' => 7]);

        Sanctum::actingAs($this->waiter);

        $this->getJson('/api/v1/tables')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 1);
    }

    public function test_precisa_de_permissao_de_mesas(): void
    {
        Sanctum::actingAs($this->userWith(['production.view'], $this->waiter->company));

        $this->getJson('/api/v1/tables')->assertForbidden();
    }
}
