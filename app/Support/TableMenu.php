<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;

/**
 * Cardápio de quem lança nas mesas (web e app do garçom): produtos ativos, vendáveis e não esgotados.
 */
class TableMenu
{
    /**
     * @return Collection<int, Product>
     */
    public static function products(): Collection
    {
        return Product::query()
            ->where('active', true)
            ->where('sellable', true)->where('sold_out', false)
            ->orderBy('name')
            ->get(['id', 'name', 'sale_price', 'unit', 'sold_out', 'category_id', 'barcode', 'sku']);
    }
}
