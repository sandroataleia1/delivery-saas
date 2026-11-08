<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductionSector;
use App\Support\ProductionDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductionSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_only_active_destination_from_same_company(): void
    {
        $company = Company::factory()->create();
        $sector = ProductionSector::factory()->create(['company_id' => $company->id]);
        $company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $company->id]);
        $this->assertSame($sector->id, (new ProductionDestination)->resolve($product)->id);
        $sector->forceFill(['active' => false])->save();
        $this->expectException(ValidationException::class);
        (new ProductionDestination)->resolve($product);
    }

    public function test_foreign_sector_cannot_be_used_as_default(): void
    {
        $company = Company::factory()->create();
        $sector = ProductionSector::factory()->create();
        $company->forceFill(['default_production_sector_id' => $sector->id])->save();
        $product = Product::factory()->create(['company_id' => $company->id]);
        $this->expectException(ValidationException::class);
        (new ProductionDestination)->resolve($product);
    }

    public function test_sold_out_is_independent_of_activity_and_stock(): void
    {
        $product = Product::factory()->create(['sold_out' => true, 'active' => true, 'stock_quantity' => '20']);
        $this->assertTrue($product->fresh()->sold_out);
        $this->assertTrue($product->fresh()->active);
        $this->assertSame('20.000', $product->fresh()->stock_quantity);
    }
}
