<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_e_logout_ficam_na_auditoria(): void
    {
        $user = $this->adminOf();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'login', 'user_id' => $user->id, 'company_id' => $user->company_id]);

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['event' => 'logout', 'user_id' => $user->id]);
    }

    public function test_senha_errada_e_recusada_e_auditada(): void
    {
        $user = $this->adminOf();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'errada'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $log = AuditLog::where('event', 'login_failed')->sole();
        $this->assertSame($user->company_id, $log->company_id);
        $this->assertSame($user->id, $log->auditable_id);
        $this->assertNull($log->user_id);
        $this->assertSame('Visitante não autenticado', $log->user_name);
        $this->assertStringNotContainsString('errada', json_encode($log->new_values));
    }

    public function test_email_desconhecido_gera_registro_sem_empresa(): void
    {
        $this->post('/login', ['email' => 'ninguem@teste.com', 'password' => 'x']);

        $this->assertDatabaseHas('audit_logs', ['event' => 'login_failed', 'company_id' => null, 'user_id' => null]);
    }

    public function test_usuario_inativo_nao_entra_e_recebe_a_mesma_mensagem(): void
    {
        $user = $this->adminOf();
        $user->forceFill(['active' => false])->saveQuietly();

        $inactive = $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email')
            ->getSession()->get('errors')->first('email');

        $this->assertGuest();
        $this->assertSame(__('auth.failed'), $inactive);
    }

    public function test_usuario_de_empresa_inativa_nao_entra(): void
    {
        $company = Company::factory()->create(['active' => false]);
        $user = $this->adminOf($company);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_bloqueia_apos_cinco_tentativas(): void
    {
        $user = $this->adminOf();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'errada']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_quem_foi_inativado_cai_na_proxima_requisicao(): void
    {
        $user = $this->adminOf();
        $this->actingAs($user)->get('/')->assertOk();

        $user->forceFill(['active' => false])->saveQuietly();

        $this->actingAs($user->fresh())->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_senha_provisoria_obriga_a_trocar_antes_de_usar(): void
    {
        $user = $this->adminOf();
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        $this->actingAs($user)->get('/')->assertRedirect(route('account.edit'));
        $this->get('/usuarios')->assertRedirect(route('account.edit'));
        $this->get('/minha-conta')->assertOk();

        $this->put('/minha-conta/senha', [
            'current_password' => 'password',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('novaSenha123', $user->fresh()->password));
        $this->get('/')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['event' => 'password_changed', 'user_id' => $user->id]);
    }

    public function test_usuarios_ja_cadastrados_com_senha_provisoria_deixam_de_ser_obrigados_a_trocar(): void
    {
        $user = $this->adminOf();
        $user->forceFill(['must_change_password' => true])->saveQuietly();

        (require database_path('migrations/2026_11_19_000001_release_pending_password_changes.php'))->up();

        $this->assertFalse($user->fresh()->must_change_password);
        $this->actingAs($user->fresh())->get('/')->assertOk();
    }

    public function test_troca_de_senha_exige_a_senha_atual(): void
    {
        $user = $this->adminOf();

        $this->actingAs($user)->put('/minha-conta/senha', [
            'current_password' => 'errada',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_redefinicao_de_senha_por_email(): void
    {
        Notification::fake();
        $user = $this->adminOf();

        $this->post('/esqueci-senha', ['email' => $user->email])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/redefinir-senha', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'novaSenha123',
                'password_confirmation' => 'novaSenha123',
            ])->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(Hash::check('novaSenha123', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'password_reset', 'user_id' => $user->id]);
    }

    public function test_esqueci_senha_nao_revela_se_o_email_existe(): void
    {
        Notification::fake();

        $this->post('/esqueci-senha', ['email' => 'ninguem@teste.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_campos_protegidos_nao_sao_atribuiveis_em_massa(): void
    {
        $user = new User(['name' => 'X', 'is_super_admin' => true, 'company_id' => 99, 'role_id' => 99]);

        $this->assertNull($user->is_super_admin);
        $this->assertNull($user->company_id);
        $this->assertNull($user->role_id);
    }
}
