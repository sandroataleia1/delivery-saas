<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Maria Silva',
            'email' => 'maria@teste.com',
            'phone' => '11999998888',
            'password' => 'senha12345',
            'password_confirmation' => 'senha12345',
            ...$overrides,
        ];
    }

    public function test_sem_permissao_nao_acessa(): void
    {
        $user = $this->userWith([]);

        $this->actingAs($user)->get('/usuarios')->assertForbidden();
    }

    public function test_quem_so_ve_nao_pode_criar(): void
    {
        $user = $this->userWith(['users.view']);

        $this->actingAs($user)->get('/usuarios')->assertOk();
        $this->get('/usuarios/novo')->assertForbidden();
        $this->post('/usuarios', $this->payload(['role_id' => $user->role_id]))->assertForbidden();
    }

    public function test_admin_cria_usuario_com_senha_definitiva(): void
    {
        $admin = $this->adminOf();
        $role = Role::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($admin)->post('/usuarios', $this->payload(['role_id' => $role->id]))
            ->assertRedirect(route('users.index'));

        $user = User::where('email', 'maria@teste.com')->sole();
        $this->assertSame($admin->company_id, $user->company_id);
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('senha12345', $user->password));
    }

    public function test_email_e_unico_na_plataforma_inteira(): void
    {
        $other = $this->adminOf();
        $admin = $this->adminOf();

        $this->actingAs($admin)->post('/usuarios', $this->payload([
            'email' => $other->email,
            'role_id' => $admin->role_id,
        ]))->assertSessionHasErrors('email');
    }

    public function test_nao_atribui_perfil_acima_do_proprio(): void
    {
        $manager = $this->userWith(['users.view', 'users.manage']);
        $admin = Role::factory()->admin()->create(['company_id' => $manager->company_id]);
        $bigger = Role::factory()->create(['company_id' => $manager->company_id, 'permissions' => ['audit.view']]);

        $this->actingAs($manager);

        foreach ([$admin, $bigger] as $role) {
            $this->post('/usuarios', $this->payload(['role_id' => $role->id]))->assertSessionHasErrors('role_id');
        }

        $this->put("/usuarios/{$manager->id}", [
            'name' => $manager->name,
            'email' => $manager->email,
            'role_id' => $admin->id,
        ])->assertSessionHasErrors('role_id');

        $this->assertSame($manager->role_id, $manager->fresh()->role_id);
    }

    public function test_nao_mexe_em_usuario_de_perfil_superior(): void
    {
        $manager = $this->userWith(['users.view', 'users.manage']);
        $admin = $this->adminOf($manager->company);

        $this->actingAs($manager);
        $this->get("/usuarios/{$admin->id}/editar")->assertForbidden();
        $this->patch("/usuarios/{$admin->id}/status")->assertForbidden();

        $this->assertTrue($admin->fresh()->active);
    }

    public function test_nao_inativa_a_si_mesmo(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->patch("/usuarios/{$admin->id}/status")->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->active);
    }

    public function test_ultimo_admin_nao_perde_o_perfil(): void
    {
        $admin = $this->adminOf();
        $role = Role::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($admin)->put("/usuarios/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $role->id,
        ])->assertSessionHasErrors('role_id');

        $this->assertTrue($admin->fresh()->isCompanyAdmin());
    }

    public function test_com_outro_admin_pode_trocar_o_perfil(): void
    {
        $admin = $this->adminOf();
        User::factory()->forRole($admin->role)->create();
        $role = Role::factory()->create(['company_id' => $admin->company_id]);

        $this->actingAs($admin)->put("/usuarios/{$admin->id}", [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $role->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($role->id, $admin->fresh()->role_id);
    }

    public function test_alteracao_vai_para_a_auditoria_sem_expor_a_senha(): void
    {
        $admin = $this->adminOf();
        $target = User::factory()->forRole($admin->role)->create(['name' => 'Antes']);
        $role = Role::factory()->create(['company_id' => $admin->company_id, 'name' => 'Caixa']);

        $this->actingAs($admin)->put("/usuarios/{$target->id}", [
            'name' => 'Depois',
            'email' => $target->email,
            'role_id' => $role->id,
            'password' => 'senha12345',
            'password_confirmation' => 'senha12345',
        ])->assertSessionHasNoErrors();

        $log = AuditLog::where('event', 'updated')->where('auditable_id', $target->id)->latest('id')->first();

        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($admin->name, $log->user_name);
        $this->assertSame('Antes', $log->old_values['name']);
        $this->assertSame('Depois', $log->new_values['name']);
        $this->assertSame('Caixa', $log->new_values['role_id']);
        $this->assertSame('••••••••', $log->new_values['password']);
        $this->assertStringNotContainsString('senha12345', json_encode($log->getAttributes()));
    }

    public function test_senha_alterada_pelo_admin_nao_obriga_troca(): void
    {
        $admin = $this->adminOf();
        $target = User::factory()->forRole($admin->role)->create();

        $this->actingAs($admin)->put("/usuarios/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'role_id' => $target->role_id,
            'password' => 'senha12345',
            'password_confirmation' => 'senha12345',
        ]);

        $this->assertFalse($target->fresh()->must_change_password);
        $this->assertTrue(Hash::check('senha12345', $target->fresh()->password));
    }
}
