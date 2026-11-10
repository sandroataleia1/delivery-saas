<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ProductionSector;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'owner_name' => 'Maria Dona',
            'email' => 'Maria@Loja.com',
            'owner_phone' => '(11) 98765-4321',
            'password' => 'senha12345',
            'password_confirmation' => 'senha12345',
            'document' => '11.222.333/0001-81',
            'legal_name' => 'Pizzaria da Maria Ltda',
            'trade_name' => 'Pizzaria da Maria',
            'segment' => 'Pizzaria',
            'phone' => '(11) 3333-4444',
            'zip_code' => '01310-100',
            'street' => 'Avenida Paulista',
            'number' => '1000',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state' => 'SP',
            ...$overrides,
        ];
    }

    public function test_pagina_abre_para_visitante(): void
    {
        $this->get('/cadastro')->assertOk();
    }

    public function test_usuario_logado_nao_ve_o_cadastro(): void
    {
        $this->actingAs($this->adminOf())->get('/cadastro')->assertRedirect(route('dashboard'));
    }

    public function test_cadastro_cria_loja_perfis_e_dono_e_ja_entra(): void
    {
        $this->post('/cadastro', $this->payload())->assertRedirect(route('dashboard'));

        $company = Company::where('document', '11222333000181')->sole();
        $owner = User::where('email', 'maria@loja.com')->sole();

        $this->assertAuthenticatedAs($owner);
        $this->assertSame('01310100', $company->zip_code);
        $this->assertSame('Pizzaria', $company->segment);
        $this->assertTrue($company->active);
        $this->assertSame($company->id, $owner->company_id);
        $this->assertTrue($owner->isCompanyAdmin());
        $this->assertFalse($owner->must_change_password);
        $this->assertFalse($owner->is_super_admin);
        $this->assertTrue(Hash::check('senha12345', $owner->password));
        $this->assertSame(5, Role::where('company_id', $company->id)->count());
        $sector = ProductionSector::where('company_id', $company->id)->sole();
        $this->assertSame('Cozinha', $sector->name);
        $this->assertTrue($sector->active);
        $this->assertSame($sector->id, $company->default_production_sector_id);

        $this->get('/')->assertOk();
    }

    public function test_cadastro_fica_na_auditoria_da_nova_empresa(): void
    {
        $this->post('/cadastro', $this->payload());

        $log = AuditLog::withoutGlobalScopes()->where('event', 'signup')->sole();

        $this->assertSame(User::sole()->id, $log->user_id);
        $this->assertSame(Company::sole()->id, $log->company_id);
        $this->assertStringNotContainsString('senha12345', AuditLog::withoutGlobalScopes()->get()->toJson());
    }

    public function test_nao_aceita_campos_que_dariam_privilegios(): void
    {
        $this->post('/cadastro', $this->payload(['is_super_admin' => true, 'active' => false, 'company_id' => 99]));

        $this->assertFalse(User::sole()->is_super_admin);
        $this->assertTrue(Company::sole()->active);
    }

    public function test_email_ja_usado_e_recusado(): void
    {
        User::factory()->create(['email' => 'maria@loja.com']);

        $this->post('/cadastro', $this->payload())->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cnpj_invalido_ou_ja_usado_e_recusado(): void
    {
        $this->post('/cadastro', $this->payload(['document' => '11222333000199']))->assertSessionHasErrors('document');

        Company::factory()->create(['document' => '11222333000181']);

        $this->post('/cadastro', $this->payload())->assertSessionHasErrors('document');
        $this->assertSame(1, Company::count());
    }

    public function test_campos_obrigatorios_cep_e_uf(): void
    {
        $this->post('/cadastro', $this->payload([
            'owner_name' => '',
            'owner_phone' => '119',
            'password' => '123',
            'zip_code' => '123',
            'state' => 'XX',
            'segment' => 'Invalido',
        ]))->assertSessionHasErrors(['owner_name', 'owner_phone', 'password', 'zip_code', 'state', 'segment']);
    }

    public function test_validacao_por_etapa_nao_cria_nada(): void
    {
        $this->withHeaders([
            'Precognition' => 'true',
            'Precognition-Validate-Only' => 'owner_name,email,owner_phone,password',
        ])->post('/cadastro', $this->payload(['document' => 'errado']))->assertNoContent();

        $this->assertSame(0, Company::count());
        $this->assertSame(0, User::count());
    }

    public function test_cadastros_em_excesso_sao_bloqueados(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post('/cadastro', $this->payload(['owner_name' => '']));
        }

        $this->post('/cadastro', $this->payload())->assertTooManyRequests();
        $this->assertSame(0, Company::count());
    }

    public function test_empresa_edita_endereco_e_a_alteracao_fica_auditada(): void
    {
        $admin = $this->adminOf();

        $this->actingAs($admin)->put('/empresa', [
            'legal_name' => $admin->company->legal_name,
            'trade_name' => $admin->company->trade_name,
            'segment' => 'Marmitaria',
            'zip_code' => '01310-100',
            'city' => 'São Paulo',
            'state' => 'SP',
        ])->assertSessionHasNoErrors();

        $this->assertSame('01310100', $admin->company->fresh()->zip_code);
        $this->assertDatabaseHas('audit_logs', ['event' => 'updated', 'auditable_type' => 'Company', 'user_id' => $admin->id]);
    }
}
