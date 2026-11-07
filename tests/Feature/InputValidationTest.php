<?php

namespace Tests\Feature;

use App\Actions\OpenTable;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InputValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Product $product;

    private DiningTable $table;

    private TableOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminOf();
        $this->actingAs($this->admin);
        $this->product = Product::factory()->create(['company_id' => $this->admin->company_id, 'stock_quantity' => '10', 'sale_price' => '10', 'track_stock' => true]);
        $this->table = DiningTable::factory()->create(['company_id' => $this->admin->company_id]);
        $this->order = app(OpenTable::class)->handle($this->table, 1, $this->admin);
        $this->openCash($this->admin);
    }

    public static function invalidFilters(): array
    {
        $cases = [];
        foreach ([
            '/clientes' => ['search'], '/clientes/buscar' => ['q'],
            '/produtos' => ['search', 'category', 'status'], '/produtos/buscar' => ['q'],
            '/fornecedores' => ['search', 'status'], '/usuarios' => ['search', 'status', 'role'],
            '/estoque' => ['search', 'low'], '/vendas' => ['number', 'status'], '/entregas/historico' => ['search'],
            '/admin/empresas' => ['search'],
        ] as $url => $fields) {
            foreach ($fields as $field) {
                $cases["$url $field array"] = [$url, $field, ['x']];
                $cases["$url $field object"] = [$url, $field, ['nested' => ['x']]];
            }
        }
        foreach ([['/produtos', 'status'], ['/fornecedores', 'status'], ['/usuarios', 'status'], ['/produtos', 'category'], ['/usuarios', 'role'], ['/estoque', 'low']] as [$url, $field]) {
            $cases["$url $field domain"] = [$url, $field, 'invalid'];
        }

        return $cases;
    }

    #[DataProvider('invalidFilters')]
    public function test_malformed_filter_is_rejected_without_mutation(string $url, string $field, mixed $value): void
    {
        if ($url === '/admin/empresas') {
            $this->admin->forceFill(['is_super_admin' => true])->saveQuietly();
        }
        $before = $this->snapshot();
        $this->getJson($url.'?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidProductIds(): array
    {
        $cases = [];
        foreach (['sale', 'entry', 'delivery', 'table', 'menu', 'recipe'] as $flow) {
            foreach (['suffix', 'fractional', 'array', 'object', 'missing', 'other-company'] as $kind) {
                $cases["$flow $kind"] = [$flow, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidProductIds')]
    public function test_invalid_product_reference_has_no_financial_or_stock_effect(string $flow, string $kind): void
    {
        $id = match ($kind) {
            'suffix' => $this->product->id.'abc',
            'fractional' => $this->product->id.'.5',
            'array' => [$this->product->id],
            'object' => (object) ['id' => $this->product->id],
            'missing' => 99999999,
            'other-company' => Product::factory()->create()->id,
        };
        [$url, $payload, $error] = $this->requestFor($flow, $id);
        $before = $this->snapshot();
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame($before, $this->snapshot());
    }

    public static function validFlows(): array
    {
        return [['sale', 'sale_items', '9.000'], ['entry', 'stock_entry_items', '11.000'], ['delivery', 'delivery_order_items', '10.000'], ['table', 'table_order_items', '10.000'], ['menu', 'menu_order_receipt_items', '10.000'], ['recipe', 'recipe_items', '10.000']];
    }

    #[DataProvider('validFlows')]
    public function test_valid_integer_reference_preserves_existing_behavior(string $flow, string $itemsTable, string $stock): void
    {
        [$url, $payload] = $this->requestFor($flow, (string) $this->product->id);
        $response = $this->postJson($url, $payload);
        if ($flow === 'menu') {
            $response->assertCreated()->assertJsonPath('receipt.status', 'pending');
        } else {
            $response->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount($itemsTable, 1);
        $this->assertSame($stock, $this->product->fresh()->stock_quantity);
        if ($flow === 'sale') {
            $this->assertDatabaseHas('sales', ['total' => '10.00']);
            $this->assertDatabaseHas('sale_payments', ['method' => 'pix', 'amount' => '10.00']);
        }
    }

    public function test_valid_search_category_status_and_pagination_remain_tenant_scoped(): void
    {
        $category = Category::factory()->create(['company_id' => $this->admin->company_id]);
        $this->product->update(['name' => 'Busca exclusiva', 'category_id' => $category->id]);
        Product::factory()->create(['name' => 'Busca exclusiva']);
        $this->get('/produtos?'.http_build_query(['search' => 'Busca exclusiva', 'category' => $category->id, 'status' => 'ativos', 'page' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('products.data', 1)->where('products.data.0.id', $this->product->id));
        $this->getJson('/produtos/buscar?q=')->assertOk()->assertExactJson([]);
        $this->getJson('/clientes/buscar?q=')->assertOk()->assertExactJson([]);
        $this->get('/estoque?low=1')->assertOk();
        $this->get('/usuarios?status=ativos&role='.$this->admin->role_id)->assertOk();
    }

    private function requestFor(string $flow, mixed $id): array
    {
        $item = ['product_id' => $id, 'quantity' => '1', 'expected_price' => '10.00', 'unit_cost' => '10.00'];
        $payload = ['client_uuid' => (string) Str::uuid(), 'items' => [$item]];
        $url = match ($flow) {
            'sale' => '/vendas', 'entry' => '/entradas', 'delivery' => '/entregas',
            'table' => "/mesas/comandas/{$this->order->id}/itens", 'menu' => '/m/'.$this->table->qr_token,
            'recipe' => '/produtos',
        };
        if ($flow === 'menu') {
            $payload += ['service_context' => $this->table->service_context, 'tracking_key' => str_repeat('b', 64)];
        } elseif ($flow === 'sale') {
            $payload['payments'] = [['method' => 'pix', 'amount' => '10.00']];
        } elseif ($flow === 'entry') {
            $payload['entry_date'] = today()->format('Y-m-d');
        } elseif ($flow === 'delivery') {
            $customer = Customer::factory()->create(['company_id' => $this->admin->company_id]);
            $payload += ['customer_id' => $customer->id, 'type' => 'pickup', 'payment_method' => 'pix'];
        } elseif ($flow === 'recipe') {
            $payload = ['name' => 'Produto com ficha', 'unit' => 'UN', 'sale_price' => '10.00', 'track_stock' => false, 'recipe' => [['ingredient_id' => $id, 'quantity' => '1']]];
        }

        return [$url, $payload, $flow === 'recipe' ? 'recipe.0.ingredient_id' : 'items.0.product_id'];
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['sales', 'sale_items', 'sale_payments', 'stock_entries', 'stock_entry_items', 'stock_movements', 'cash_movements', 'bills', 'table_order_items', 'menu_order_receipts', 'menu_order_receipt_items', 'delivery_orders', 'delivery_order_items', 'products', 'recipe_items'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
