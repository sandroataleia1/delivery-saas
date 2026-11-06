<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_exige_permissao(): void
    {
        $this->actingAs($this->userWith([]))->get('/auditoria')->assertForbidden();
        $this->actingAs($this->userWith(['audit.view']))->get('/auditoria')->assertOk();
    }

    public function test_registro_nao_pode_ser_alterado(): void
    {
        $log = Audit::record('login', description: 'teste');

        $this->expectException(LogicException::class);

        $log->description = 'adulterado';
        $log->save();
    }

    public function test_registro_nao_pode_ser_excluido(): void
    {
        $log = Audit::record('login', description: 'teste');

        $this->expectException(LogicException::class);

        $log->delete();
    }

    public function test_guarda_ip_e_rota_de_quem_fez(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])
            ->put('/minha-conta', ['name' => 'Novo Nome']);

        $log = AuditLog::where('event', 'updated')->latest('id')->first();

        $this->assertSame('10.0.0.7', $log->ip_address);
        $this->assertSame('PUT minha-conta', $log->url);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_alteracao_sem_mudanca_real_nao_gera_registro(): void
    {
        $admin = $this->adminOf()->fresh();
        $before = AuditLog::count();

        $this->actingAs($admin)->put('/minha-conta', ['name' => $admin->name, 'phone' => $admin->phone]);

        $this->assertSame($before, AuditLog::count());
    }

    public function test_filtros(): void
    {
        $admin = $this->adminOf();
        $other = $this->userWith([], $admin->company);

        Audit::record('login', description: 'do admin', actor: $admin);
        Audit::record('logout', description: 'do outro', actor: $other);

        $this->actingAs($admin);

        $this->get("/auditoria?user={$other->id}&event=logout")->assertInertia(
            fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.description', 'do outro')
        );
        $this->get('/auditoria?search=do+admin')->assertInertia(fn (Assert $page) => $page->has('logs.data', 1));
        $this->get('/auditoria?to=2000-01-01')->assertInertia(fn (Assert $page) => $page->has('logs.data', 0));
    }

    public function test_alteracao_e_apresentada_com_nomes_legiveis(): void
    {
        $admin = $this->adminOf();

        $log = Audit::record('updated', $admin, old: ['active' => true, 'name' => null], new: ['active' => false, 'name' => 'Ana'], actor: $admin);

        $this->assertSame([
            ['field' => 'Ativo', 'old' => 'Sim', 'new' => 'Não'],
            ['field' => 'Nome', 'old' => '—', 'new' => 'Ana'],
        ], $log->present()['changes']);
    }
}
