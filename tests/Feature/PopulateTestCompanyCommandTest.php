<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PopulateTestCompanyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_popula_empresa_com_cardapio_clientes_vendas_e_contas_uma_vez_so(): void
    {
        $admin = $this->adminOf();
        $companyId = $admin->company_id;
        $other = Product::factory()->create();

        $this->artisan('demo:populate', ['company' => $companyId, '--days' => 3])->assertSuccessful();

        $products = Product::withoutGlobalScopes()->where('company_id', $companyId);
        $pizza = (clone $products)->where('name', 'Pizza')->sole();
        $this->assertTrue($pizza->isPizza());
        $this->assertSame(['Pequena', 'Média', 'Grande'], $pizza->pizzaSizes()->orderBy('position')->pluck('name')->all());
        $this->assertSame(10, Customer::withoutGlobalScopes()->where('company_id', $companyId)->count());

        $sales = Sale::withoutGlobalScopes()->where('company_id', $companyId);
        $this->assertGreaterThanOrEqual(18, (clone $sales)->count());
        $this->assertTrue((clone $sales)->whereHas('items', fn ($query) => $query->where('product_id', $pizza->id))->exists());
        $this->assertSame(
            [today()->subDays(3)->toDateString(), today()->subDays(2)->toDateString(), today()->subDay()->toDateString()],
            CashSession::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('closed_at')->orderBy('id')->get()->map(fn (CashSession $session) => $session->opened_at->toDateString())->all(),
        );
        $this->assertFalse(CashSession::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('closed_at')->exists());
        $this->assertTrue(Bill::withoutGlobalScopes()->where('company_id', $companyId)->where('type', Bill::RECEIVABLE)->exists());
        $this->assertSame(0, Sale::withoutGlobalScopes()->where('company_id', $other->company_id)->count());

        $productCount = (clone $products)->count();
        $this->artisan('demo:populate', ['company' => $companyId, '--days' => 3])->assertFailed();
        $this->assertSame($productCount, (clone $products)->count());
    }
}
