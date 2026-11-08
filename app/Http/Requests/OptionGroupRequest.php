<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OptionGroupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $group = $this->route('group');

        return [
            'name' => ['required', 'string', 'max:80'],
            'min_choices' => ['required', 'integer', 'min:0', 'max:50'],
            'max_choices' => ['required', 'integer', 'min:1', 'max:50', 'gte:min_choices'],
            'allows_quantity' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
            'product_ids' => ['present', 'array', 'max:200'],
            'product_ids.*' => ['bail', 'integer', 'distinct', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'options' => ['required', 'array', 'min:1', 'max:60'],
            'options.*.id' => ['nullable', 'integer', Rule::exists('options', 'id')->where('company_id', $companyId)->where('option_group_id', $group?->id ?? 0)],
            'options.*.name' => ['required', 'string', 'max:80'],
            'options.*.price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
            'options.*.active' => ['required', 'boolean'],
            'options.*.recipe' => ['present', 'array', 'max:50'],
            'options.*.recipe.*.ingredient_id' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where('company_id', $companyId)->where('track_stock', true),
            ],
            'options.*.recipe.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'min_choices' => 'mínimo de escolhas',
            'max_choices' => 'máximo de escolhas',
            'allows_quantity' => 'permitir quantidade',
            'product_ids' => 'produtos',
            'product_ids.*' => 'produto',
            'options' => 'opções',
            'options.*.name' => 'nome da opção',
            'options.*.price' => 'preço',
            'options.*.recipe' => 'ficha',
            'options.*.recipe.*.ingredient_id' => 'insumo',
            'options.*.recipe.*.quantity' => 'quantidade',
        ];
    }
}
