<?php

namespace App\Http\Requests;

use App\Models\Option;
use App\Models\OptionGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PizzaSetupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = $this->user()->company_id;
        $product = $this->route('product');
        $ingredient = [
            'bail',
            'required',
            'integer',
            Rule::exists('products', 'id')->where('company_id', $companyId)->where('track_stock', true),
        ];
        $quantity = ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'];
        $price = ['numeric', 'decimal:0,2', 'min:0', 'max:9999999'];

        return [
            'pizza_pricing' => ['required', Rule::in(['highest', 'average'])],
            'sizes' => ['required', 'array', 'min:1', 'max:10'],
            'sizes.*.id' => ['nullable', 'integer', Rule::exists('pizza_sizes', 'id')->where('company_id', $companyId)->where('product_id', $product->id)],
            'sizes.*.name' => ['required', 'string', 'max:40'],
            'sizes.*.slices' => ['nullable', 'integer', 'min:1', 'max:60'],
            'sizes.*.max_flavors' => ['required', 'integer', 'min:1', 'max:4'],
            'sizes.*.active' => ['required', 'boolean'],
            'sizes.*.recipe' => ['present', 'array', 'max:50'],
            'sizes.*.recipe.*.ingredient_id' => $ingredient,
            'sizes.*.recipe.*.quantity' => $quantity,
            'flavors' => ['present', 'array', 'max:60'],
            'flavors.*.id' => ['nullable', 'integer'],
            'flavors.*.name' => ['required', 'string', 'max:80'],
            'flavors.*.active' => ['required', 'boolean'],
            'flavors.*.prices' => ['present', 'array'],
            'flavors.*.prices.*' => ['required', ...$price],
            'flavors.*.recipes' => ['present', 'array'],
            'flavors.*.recipes.*' => ['array', 'max:50'],
            'flavors.*.recipes.*.*.ingredient_id' => $ingredient,
            'flavors.*.recipes.*.*.quantity' => $quantity,
            'size_prices' => ['nullable', 'array'],
            'size_prices.*' => ['array'],
            'size_prices.*.*' => ['nullable', ...$price],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'pizza_pricing' => 'regra de preço',
            'sizes' => 'tamanhos',
            'sizes.*.name' => 'nome do tamanho',
            'sizes.*.slices' => 'fatias',
            'sizes.*.max_flavors' => 'máximo de sabores',
            'flavors.*.name' => 'nome do sabor',
            'flavors.*.prices' => 'preços',
            'flavors.*.prices.*' => 'preço',
            'size_prices.*.*' => 'preço',
            '*.recipe.*.ingredient_id' => 'insumo',
            '*.recipes.*.*.ingredient_id' => 'insumo',
            '*.recipe.*.quantity' => 'quantidade',
            '*.recipes.*.*.quantity' => 'quantidade',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $this->rejectRepeatedIngredients($validator);

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $product = $this->route('product');
            $keys = [];
            $activeKeys = [];

            foreach (array_values($this->input('sizes')) as $position => $size) {
                $key = isset($size['id']) ? (string) $size['id'] : 'n'.$position;
                $keys[] = $key;

                if ($size['active']) {
                    $activeKeys[] = $key;
                }
            }

            $known = fn (int|string $key): bool => in_array((string) $key, $keys, true) || in_array('n'.$key, $keys, true);
            $resolve = fn (int|string $key): string => in_array((string) $key, $keys, true) ? (string) $key : 'n'.$key;

            $flavorGroup = $product->optionGroups()->where('kind', OptionGroup::FLAVOR)->first();

            foreach (array_values($this->input('flavors')) as $index => $flavor) {
                if (isset($flavor['id']) && ! $flavorGroup?->options()->whereKey($flavor['id'])->exists()) {
                    $validator->errors()->add("flavors.$index.id", 'Sabor inválido.');
                }

                foreach (array_keys($flavor['prices']) as $key) {
                    if (! $known($key)) {
                        $validator->errors()->add("flavors.$index.prices", 'Tamanho inválido.');
                    }
                }

                foreach (array_keys($flavor['recipes']) as $key) {
                    if (! $known($key)) {
                        $validator->errors()->add("flavors.$index.recipes", 'Tamanho inválido.');
                    }
                }

                if ($flavor['active']) {
                    $priced = array_map($resolve, array_keys($flavor['prices']));

                    if (array_diff($activeKeys, $priced) !== []) {
                        $validator->errors()->add("flavors.$index.prices", 'Informe o preço do sabor em todos os tamanhos ativos.');
                    }
                }
            }

            foreach ($this->input('size_prices', []) as $optionId => $prices) {
                $isExtra = Option::whereKey($optionId)->whereHas('group', fn ($query) => $query->where('kind', OptionGroup::EXTRA))->exists();

                if (! $isExtra) {
                    $validator->errors()->add("size_prices.$optionId", 'Opção inválida.');
                }

                foreach (array_keys($prices) as $key) {
                    if (! $known($key)) {
                        $validator->errors()->add("size_prices.$optionId", 'Tamanho inválido.');
                    }
                }
            }
        }];
    }

    /** Repetição é conferida dentro de cada ficha: insumos iguais em fichas diferentes são válidos. */
    private function rejectRepeatedIngredients(Validator $validator): void
    {
        $lists = [];

        foreach ((array) $this->input('sizes') as $index => $size) {
            $lists["sizes.$index.recipe"] = $size['recipe'] ?? null;
        }

        foreach ((array) $this->input('flavors') as $index => $flavor) {
            foreach ((array) ($flavor['recipes'] ?? []) as $key => $items) {
                $lists["flavors.$index.recipes.$key"] = $items;
            }
        }

        foreach ($lists as $path => $items) {
            if (! is_array($items)) {
                continue;
            }

            $ids = array_column(array_filter($items, 'is_array'), 'ingredient_id');

            foreach (array_keys(array_filter(array_count_values(array_filter($ids, 'is_scalar')), fn ($count) => $count > 1)) as $duplicated) {
                $position = array_keys($ids, $duplicated, false)[1];
                $validator->errors()->add("$path.$position.ingredient_id", 'O insumo está repetido na ficha.');
            }
        }
    }
}
