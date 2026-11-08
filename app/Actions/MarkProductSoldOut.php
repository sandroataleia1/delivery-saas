<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class MarkProductSoldOut
{
    public function handle(Product $product, bool $soldOut): void
    {
        Gate::authorize('products.manage');
        DB::transaction(function () use ($product, $soldOut) {
            Company::whereKey($product->company_id)->lockForUpdate()->firstOrFail();
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail()->forceFill(['sold_out' => $soldOut])->save();
        });
    }
}
