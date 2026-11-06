<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->otherAdmin = $this->adminOf();
    }

    public function test_listagem_de_usuarios_mostra_apenas_a_propria_empresa(): void
    {
        $this->actingAs($this->admin)->get('/usuarios')->assertInertia(
            fn (Assert $page) => $page->component('users/Index')
                ->has('users.data', 1)
                ->where('users.data.0.id', $this->admin->id)
        );
    }

    public function test_listagem_de_perfis_mostra_apenas_a_propria_empresa(): void
    {
        $this->actingAs($this->admin)->get('/perfis')->assertInertia(
            fn (Assert $page) => $page->has('roles', 1)
        );
    }

    public function test_nao_acessa_usuario_de_outra_empresa(): void
    {
        $id = $this->otherAdmin->id;
        $payload = ['name' => 'Invadido', 'email' => 'x@teste.com', 'role_id' => $this->admin->role_id];

        $this->actingAs($this->admin);
        $this->get("/usuarios/$id/editar")->assertNotFound();
        $this->put("/usuarios/$id", $payload)->assertNotFound();
        $this->patch("/usuarios/$id/status")->assertNotFound();

        $this->assertNotSame('Invadido', User::withoutGlobalScopes()->find($id)->name);
    }

    public function test_nao_acessa_perfil_de_outra_empresa(): void
    {
        $role = Role::factory()->create(['company_id' => $this->otherAdmin->company_id]);

        $this->actingAs($this->admin);
        $this->get("/perfis/{$role->id}/editar")->assertNotFound();
        $this->delete("/perfis/{$role->id}")->assertNotFound();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_nao_atribui_perfil_de_outra_empresa(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', [
            'name' => 'Novo',
            'email' => 'novo@teste.com',
            'role_id' => $this->otherAdmin->role_id,
            'password' => 'senha12345',
            'password_confirmation' => 'senha12345',
        ])->assertSessionHasErrors('role_id');
    }

    public function test_auditoria_mostra_apenas_a_propria_empresa(): void
    {
        AuditLog::forceCreateQuietly(['company_id' => $this->otherAdmin->company_id, 'event' => 'login', 'description' => 'isolamento-auditoria: outra']);
        AuditLog::forceCreateQuietly(['company_id' => $this->admin->company_id, 'event' => 'login', 'description' => 'isolamento-auditoria: minha']);

        $this->actingAs($this->admin)->get('/auditoria?search=isolamento-auditoria')->assertInertia(
            fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.description', 'isolamento-auditoria: minha')
        );
    }

    public function test_empresa_nova_comeca_sem_dados_de_outra(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminOf($company);

        $this->actingAs($admin)->get('/usuarios')->assertInertia(
            fn (Assert $page) => $page->has('users.data', 1)
        );
    }
}
