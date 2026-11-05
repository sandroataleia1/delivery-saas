<?php

namespace App\Http\Requests;

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
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'sku' => ['nullable', 'string', 'max:40', $unique('sku')],
            'barcode' => ['nullable', 'string', 'max:20', $unique('barcode')],
            'unit' => ['required', Rule::in(array_keys(config('stock.units')))],
            'sale_price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'cost_price' => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999'],
            'track_stock' => ['required', 'boolean'],
            'min_stock' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
            'initial_stock' => [Rule::excludeIf($product !== null), 'nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'category_id' => 'categoria',
            'sku' => 'código interno',
            'barcode' => 'código de barras',
            'unit' => 'unidade',
            'sale_price' => 'preço de venda',
            'cost_price' => 'custo',
            'track_stock' => 'controlar estoque',
            'min_stock' => 'estoque mínimo',
            'initial_stock' => 'estoque inicial',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $product = $this->route('product');

            if ($product?->track_stock && ! $this->boolean('track_stock') && bccomp($product->stock_quantity, '0', 3) > 0) {
                $validator->errors()->add('track_stock', 'Zere o saldo do produto antes de parar de controlar o estoque.');
            }
        }];
    }
}
