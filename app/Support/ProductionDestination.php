<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductionSector;
use Illuminate\Validation\ValidationException;

class ProductionDestination
{
    public function resolve(Product $product): ProductionSector
    {
        $category = $product->category_id ? Category::withoutGlobalScopes()->where('company_id', $product->company_id)->find($product->category_id) : null;
        $id = $category?->production_sector_id ?? Company::findOrFail($product->company_id)->default_production_sector_id;
        $sector = ProductionSector::withoutGlobalScopes()->where('company_id', $product->company_id)->where('active', true)->find($id);
        if (! $sector) {
            throw ValidationException::withMessages(['production' => 'Configure um setor ativo na categoria ou um setor padrão da empresa.']);
        }

        return $sector;
    }
}
