<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyAdminTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'legal_name' => 'Hamburgueria Ltda',
            'trade_name' => 'Hamburgueria',
            'document' => '11.222.333/0001-81',
            'admin_name' => 'Dono',
            'admin_email' => 'dono@teste.com',
            'admin_password' => 'senha12345',
            'admin_password_confirmation' => 'senha12345',
            ...$overrides,
        ];
    }

    public function test_usuario_de_empresa_nao_acessa_o_painel(): void
    {
        $this->actingAs($this->adminOf())->get('/admin/empresas')->assertForbidden();
        $this->actingAs($this->adminOf())->get('/admin/auditoria')->assertForbidden();
    }

    public function test_super_admin_vai_para_o_painel_e_nao_entra_na_area_da_empresa(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->get('/')->assertRedirect(route('admin.companies.index'));
        $this->get('/usuarios')->assertRedirect(route('admin.companies.index'));
    }

    public function test_criar_empresa_gera_perfis_e_administrador(): void
    {
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->post('/admin/empresas', $this->payload())
            ->assertRedirect(route('admin.companies.index'));

        $company = Company::where('document', '11222333000181')->sole();
        $owner = User::where('email', 'dono@teste.com')->sole();

        $this->assertCount(5, Role::where('company_id', $company->id)->get());
        $this->assertSame($company->id, $owner->company_id);
        $this->assertTrue($owner->isCompanyAdmin());
        $this->assertTrue($owner->must_change_password);
        $this->assertSame($super->id, AuditLog::where('auditable_type', 'Company')->sole()->user_id);
        $this->assertSame($company->id, AuditLog::where('auditable_type', 'Company')->sole()->company_id);
    }

    public function test_cnpj_invalido_ou_repetido_e_recusado(): void
    {
        $super = User::factory()->superAdmin()->create();
        Company::factory()->create(['document' => '11222333000181']);

        $this->actingAs($super);
        $this->post('/admin/empresas', $this->payload(['document' => '11222333000199']))->assertSessionHasErrors('document');
        $this->post('/admin/empresas', $this->payload())->assertSessionHasErrors('document');
    }

    public function test_aceita_cnpj_alfanumerico(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->post('/admin/empresas', $this->payload(['document' => '12.abc.345/01de-35']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('companies', ['document' => '12ABC34501DE35']);
    }

    public function test_inativar_empresa_derruba_os_usuarios(): void
    {
        $admin = $this->adminOf();
        $super = User::factory()->superAdmin()->create();

        $this->actingAs($super)->patch("/admin/empresas/{$admin->company_id}/status")->assertSessionHas('success');

        $this->assertFalse($admin->company->fresh()->active);
        $this->actingAs($admin->fresh())->get('/')->assertRedirect(route('login'));
    }

    public function test_super_admin_ve_a_auditoria_de_todas_as_empresas(): void
    {
        $a = $this->adminOf();
        $b = $this->adminOf();

        $this->actingAs(User::factory()->superAdmin()->create())->get('/admin/auditoria')->assertInertia(
            fn (Assert $page) => $page->component('audit/Index')
                ->where('isPlatform', true)
                ->where('logs.total', AuditLog::count())
        );
    }

    public function test_empresa_edita_os_proprios_dados_mas_nao_o_cnpj(): void
    {
        $admin = $this->adminOf();
        $document = $admin->company->document;

        $this->actingAs($admin)->put('/empresa', [
            'legal_name' => 'Nova Razão',
            'trade_name' => 'Novo Nome',
            'document' => '11222333000181',
        ])->assertSessionHasNoErrors();

        $company = $admin->company->fresh();
        $this->assertSame('Novo Nome', $company->trade_name);
        $this->assertSame($document, $company->document);
    }

    public function test_dados_da_empresa_exigem_permissao(): void
    {
        $this->actingAs($this->userWith([]))->get('/empresa')->assertForbidden();
    }
}
