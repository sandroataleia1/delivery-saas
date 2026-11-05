<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_perfil_com_permissoes(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->post('/perfis', [
            'name' => 'Conferente',
            'permissions' => ['audit.view'],
        ])->assertRedirect(route('roles.index'));

        $this->assertSame(['audit.view'], Role::where('name', 'Conferente')->sole()->permissions);
    }

    public function test_recusa_permissao_inexistente(): void
    {
        $this->actingAs($this->adminOf())->post('/perfis', [
            'name' => 'X',
            'permissions' => ['nao.existe'],
        ])->assertSessionHasErrors('permissions.0');
    }

    public function test_nao_concede_permissao_que_nao_possui(): void
    {
        $user = $this->userWith(['roles.view', 'roles.manage']);

        $this->actingAs($user)->post('/perfis', [
            'name' => 'Poderoso',
            'permissions' => ['users.manage'],
        ])->assertSessionHasErrors('permissions');
    }

    public function test_nome_repetido_so_e_recusado_na_mesma_empresa(): void
    {
        $admin = $this->adminOf();
        Role::factory()->create(['company_id' => $admin->company_id, 'name' => 'Caixa']);
        Role::factory()->create(['name' => 'Estoquista']);

        $this->actingAs($admin);
        $this->post('/perfis', ['name' => 'Caixa', 'permissions' => []])->assertSessionHasErrors('name');
        $this->post('/perfis', ['name' => 'Estoquista', 'permissions' => []])->assertSessionHasNoErrors();
    }

    public function test_perfil_administrador_e_intocavel(): void
    {
        $admin = $this->adminOf();
        $roleId = $admin->role_id;

        $this->actingAs($admin);
        $this->get("/perfis/$roleId/editar")->assertForbidden();
        $this->put("/perfis/$roleId", ['name' => 'X', 'permissions' => []])->assertForbidden();
        $this->delete("/perfis/$roleId")->assertForbidden();
    }

    public function test_nao_exclui_perfil_com_usuarios(): void
    {
        $admin = $this->adminOf();
        $role = Role::factory()->create(['company_id' => $admin->company_id]);
        User::factory()->forRole($role)->create();

        $this->actingAs($admin)->delete("/perfis/{$role->id}")->assertSessionHas('error');

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_exclui_perfil_vazio_e_a_exclusao_fica_auditada(): void
    {
        $admin = $this->adminOf();
        $role = Role::factory()->create(['company_id' => $admin->company_id, 'name' => 'Temporário']);

        $this->actingAs($admin)->delete("/perfis/{$role->id}")->assertSessionHas('success');

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'deleted',
            'auditable_type' => 'Role',
            'auditable_id' => $role->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_permissao_vale_imediatamente_ao_mudar_o_perfil(): void
    {
        $user = $this->userWith(['users.view']);
        $this->actingAs($user)->get('/usuarios')->assertOk();

        $user->role->update(['permissions' => []]);

        $this->actingAs($user->fresh())->get('/usuarios')->assertForbidden();
    }
}
