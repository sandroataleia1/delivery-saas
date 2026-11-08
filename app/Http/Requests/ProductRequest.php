<?php

namespace App\Http\Requests;

use App\Models\RecipeItem;
use App\Support\PublicImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $product = $this->route('product');
        $unique = fn (string $column) => Rule::unique('products', $column)->where('company_id', $companyId)->ignore($product);

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'sku' => ['nullable', 'string', 'max:40', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:20', $unique('barcode')],
            'unit' => ['required', Rule::in(array_keys(config('stock.units')))],
            'sellable' => ['sometimes', 'boolean'],
            'sold_out' => ['sometimes', 'boolean'],
            'sale_price' => ['exclude_if:sellable,false', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'cost_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999'],
            'track_stock' => ['required', 'boolean'],
            'min_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
            'initial_stock' => [Rule::excludeIf($product !== null), 'nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
            'image' => PublicImage::RULES,
            'remove_image' => ['sometimes', 'boolean'],
            'recipe' => ['present', 'array', 'max:50'],
            'recipe.*.ingredient_id' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where('company_id', $companyId)->where('track_stock', true),
            ],
            'recipe.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'description' => 'descrição',
            'category_id' => 'categoria',
            'sku' => 'código interno',
            'barcode' => 'código de barras',
            'unit' => 'unidade',
            'sale_price' => 'preço de venda',
            'cost_price' => 'custo',
            'track_stock' => 'controlar estoque',
            'sellable' => 'disponível para venda',
            'min_stock' => 'estoque mínimo',
            'initial_stock' => 'estoque inicial',
            'image' => 'foto',
            'recipe' => 'ficha técnica',
            'recipe.*.ingredient_id' => 'insumo',
            'recipe.*.quantity' => 'quantidade',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $product = $this->route('product');

            $stopsTracking = $product?->track_stock && ! $this->boolean('track_stock');

            if ($stopsTracking && bccomp($product->stock_quantity, '0', 3) > 0) {
                $validator->errors()->add('track_stock', 'Zere o saldo do produto antes de parar de controlar o estoque.');
            }

            if ($stopsTracking && RecipeItem::where('ingredient_id', $product->id)->exists()) {
                $validator->errors()->add('track_stock', 'Este produto é insumo de uma ficha técnica e precisa controlar estoque.');
            }

            // Evita baixa em dobro: ou o produto tem saldo próprio, ou baixa os insumos.
            if ($this->boolean('track_stock') && ! empty($this->input('recipe'))) {
                $validator->errors()->add('recipe', 'Produto com ficha técnica não pode controlar estoque próprio.');
            }
        }];
    }
}
