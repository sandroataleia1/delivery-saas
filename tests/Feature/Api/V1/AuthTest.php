<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, string $password = 'password'): TestResponse
    {
        return $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password, 'device_name' => 'Moto G']);
    }

    private function waiter(): User
    {
        return $this->userWith(['tables.operate'], $this->adminOf()->company);
    }

    public function test_garcom_entra_e_recebe_token_com_a_empresa(): void
    {
        $waiter = $this->waiter();

        $token = $this->login($waiter)->assertOk()->assertJsonPath('user.name', $waiter->name)->json('token');

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer $token"])
            ->assertOk()
            ->assertJsonPath('data.id', $waiter->id)
            ->assertJsonPath('data.company.name', $waiter->company->trade_name)
            ->assertJsonPath('data.company.logo_url', null)
            ->assertJsonPath('data.permissions', ['tables.operate']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'login', 'user_id' => $waiter->id]);
        $this->assertSame('Moto G', PersonalAccessToken::sole()->name);
    }

    public function test_senha_errada_nao_gera_token_e_e_auditada(): void
    {
        $this->login($this->waiter(), 'errada')->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(0, PersonalAccessToken::count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'login_failed']);
    }

    public function test_perfil_sem_mesas_nao_usa_o_app(): void
    {
        $cook = $this->userWith(['production.view'], $this->adminOf()->company);

        $this->login($cook)->assertForbidden()->assertJsonPath('message', 'Seu perfil não atende mesas.');

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_conta_inativa_nao_entra(): void
    {
        $waiter = $this->waiter();
        $waiter->forceFill(['active' => false])->saveQuietly();

        $this->login($waiter)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_bloqueia_apos_cinco_tentativas(): void
    {
        $waiter = $this->waiter();

        foreach (range(1, 5) as $attempt) {
            $this->login($waiter, 'errada');
        }

        $this->login($waiter)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_usuario_inativado_cai_na_proxima_chamada(): void
    {
        $waiter = $this->waiter();
        $token = $this->login($waiter)->json('token');

        $waiter->forceFill(['active' => false])->saveQuietly();

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer $token"])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Seu acesso está desativado.');
        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_admin_trocar_a_senha_revoga_os_tokens(): void
    {
        $admin = $this->adminOf();
        $waiter = $this->userWith(['tables.operate'], $admin->company);
        $waiter->createToken('Moto G');

        $this->actingAs($admin)->put("/usuarios/{$waiter->id}", [
            'name' => $waiter->name,
            'email' => $waiter->email,
            'role_id' => $waiter->role_id,
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $waiter->tokens()->count());
    }

    public function test_garcom_trocar_a_propria_senha_revoga_os_tokens(): void
    {
        $waiter = $this->waiter();
        $waiter->createToken('Moto G');

        $this->actingAs($waiter)->put('/minha-conta/senha', [
            'current_password' => 'password',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $waiter->tokens()->count());
    }

    public function test_redefinir_a_senha_por_email_revoga_os_tokens(): void
    {
        $waiter = $this->waiter();
        $waiter->createToken('Moto G');

        $this->post('/redefinir-senha', [
            'token' => Password::createToken($waiter),
            'email' => $waiter->email,
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, $waiter->tokens()->count());
    }

    public function test_admin_inativar_revoga_os_tokens(): void
    {
        $admin = $this->adminOf();
        $waiter = $this->userWith(['tables.operate'], $admin->company);
        $waiter->createToken('Moto G');

        $this->actingAs($admin)->patch("/usuarios/{$waiter->id}/status");

        $this->assertFalse($waiter->fresh()->active);
        $this->assertSame(0, $waiter->tokens()->count());
    }

    public function test_sair_revoga_so_o_token_do_aparelho(): void
    {
        $waiter = $this->waiter();
        $first = $this->login($waiter)->json('token');
        $second = $this->login($waiter)->json('token');

        $this->postJson('/api/v1/logout', [], ['Authorization' => "Bearer $first"])->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/me', ['Authorization' => "Bearer $second"])->assertOk();
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_versao_minima_do_app_e_publica(): void
    {
        $this->getJson('/api/v1/app')->assertOk()->assertJsonPath('min_version_code', 1);
    }
}
