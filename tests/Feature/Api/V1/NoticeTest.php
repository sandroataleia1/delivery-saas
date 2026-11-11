<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use App\Models\WaiterNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->waiter = $this->userWith(['tables.operate']);
    }

    private function noticeFor(User $user, array $attributes = []): WaiterNotice
    {
        return WaiterNotice::factory()->create(['company_id' => $user->company_id, 'user_id' => $user->id, ...$attributes]);
    }

    public function test_lista_so_os_avisos_do_usuario_com_contagem_de_nao_lidos(): void
    {
        $old = $this->noticeFor($this->waiter, ['read_at' => now(), 'title' => 'Mesa 2: 1 item pronto — Bar']);
        $new = $this->noticeFor($this->waiter, ['kind' => 'menu_order', 'title' => 'Mesa 5: pedido pelo QR', 'body' => '2 itens · R$ 20,00 · aguarda confirmação']);
        $colleague = $this->userWith(['tables.operate'], $this->waiter->company);
        $this->noticeFor($colleague);
        Sanctum::actingAs($this->waiter);

        $this->getJson(route('api.v1.notices'))->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.kind', 'menu_order')
            ->assertJsonPath('data.0.title', 'Mesa 5: pedido pelo QR')
            ->assertJsonPath('data.0.body', '2 itens · R$ 20,00 · aguarda confirmação')
            ->assertJsonPath('data.0.read', false)
            ->assertJsonPath('data.1.id', $old->id)
            ->assertJsonPath('data.1.read', true)
            ->assertJsonStructure(['data' => [['id', 'kind', 'title', 'body', 'table_id', 'order_id', 'read', 'created_at']]]);
    }

    public function test_lista_no_maximo_50_avisos(): void
    {
        WaiterNotice::factory()->count(51)->create(['company_id' => $this->waiter->company_id, 'user_id' => $this->waiter->id]);
        Sanctum::actingAs($this->waiter);

        $this->getJson(route('api.v1.notices'))->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('unread', 51);
    }

    public function test_marcar_como_lido_ate_um_id(): void
    {
        $first = $this->noticeFor($this->waiter);
        $second = $this->noticeFor($this->waiter);
        $third = $this->noticeFor($this->waiter);
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.notices.read'), ['up_to_id' => $second->id])->assertOk()->assertJsonPath('unread', 1);

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($third->fresh()->read_at);
    }

    public function test_nao_marca_aviso_de_outro_usuario(): void
    {
        $colleague = $this->userWith(['tables.operate'], $this->waiter->company);
        $theirs = $this->noticeFor($colleague);
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.notices.read'), ['up_to_id' => $theirs->id])->assertOk()->assertJsonPath('unread', 0);

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_marcar_como_lido_exige_id(): void
    {
        Sanctum::actingAs($this->waiter);

        $this->postJson(route('api.v1.notices.read'), [])->assertJsonValidationErrors('up_to_id');
    }

    public function test_exige_login(): void
    {
        $this->getJson(route('api.v1.notices'))->assertUnauthorized();
        $this->postJson(route('api.v1.notices.read'), ['up_to_id' => 1])->assertUnauthorized();
    }
}
