<?php

namespace App\Http\Requests;

use App\Models\OptionGroup;
use App\Models\RecipeItem;
use App\Support\PublicImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    /**
     * O FormData do navegador omite listas vazias; em multipart a ausência de `recipe` significa lista vazia.
     * Clientes JSON continuam obrigados a enviar a chave.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->isJson() && ! $this->has('recipe')) {
            $this->merge(['recipe' => []]);
        }

        if (! $this->isJson() && $this->boolean('is_pizza') && is_array($pizza = $this->input('pizza', []))) {
            $pizza['sizes'] ??= [];
            $pizza['flavors'] = array_map(
                fn ($flavor) => is_array($flavor) ? $flavor + ['prices' => []] : $flavor,
                $pizza['flavors'] ?? [],
            );
            $this->merge(['pizza' => $pizza]);
        }
    }

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
            'is_pizza' => ['sometimes', 'boolean'],
            'sale_price' => ['exclude_if:sellable,false', Rule::excludeIf($this->boolean('is_pizza')), 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
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
            ...($this->boolean('is_pizza') ? $this->pizzaRules($companyId, $product?->id) : []),
        ];
    }

    /**
     * Tamanhos e sabores da pizza no próprio cadastro. Fichas e preço de adicionais por tamanho ficam na tela de ajustes da pizza.
     *
     * @return array<string, mixed>
     */
    private function pizzaRules(int $companyId, ?int $productId): array
    {
        return [
            'pizza' => ['required', 'array'],
            'pizza.pizza_pricing' => ['required', Rule::in(['highest', 'average'])],
            'pizza.sizes' => ['present', 'array', 'max:10'],
            'pizza.sizes.*.id' => ['nullable', 'integer', Rule::exists('pizza_sizes', 'id')->where('company_id', $companyId)->where('product_id', $productId ?? 0)],
            'pizza.sizes.*.name' => ['required', 'string', 'max:40'],
            'pizza.sizes.*.slices' => ['nullable', 'integer', 'min:1', 'max:60'],
            'pizza.sizes.*.max_flavors' => ['required', 'integer', 'min:1', 'max:4'],
            'pizza.sizes.*.active' => ['required', 'boolean'],
            'pizza.flavors' => ['present', 'array', 'max:60'],
            'pizza.flavors.*.id' => ['nullable', 'integer'],
            'pizza.flavors.*.name' => ['required', 'string', 'max:80'],
            'pizza.flavors.*.active' => ['required', 'boolean'],
            'pizza.flavors.*.prices' => ['present', 'array'],
            'pizza.flavors.*.prices.*' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999'],
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
            'pizza.pizza_pricing' => 'regra de preço',
            'pizza.sizes' => 'tamanhos',
            'pizza.sizes.*.id' => 'tamanho',
            'pizza.sizes.*.name' => 'nome do tamanho',
            'pizza.sizes.*.slices' => 'fatias',
            'pizza.sizes.*.max_flavors' => 'máximo de sabores',
            'pizza.flavors' => 'sabores',
            'pizza.flavors.*.name' => 'nome do sabor',
            'pizza.flavors.*.prices.*' => 'preço',
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

            if ($this->boolean('is_pizza') && $validator->errors()->isEmpty()) {
                $this->validatePizza($validator);
            }
        }];
    }

    /** Pizza ligada precisa de um tamanho ativo e de um sabor ativo com preço em todos os tamanhos ativos. */
    private function validatePizza(Validator $validator): void
    {
        $product = $this->route('product');
        $keys = [];
        $activeKeys = [];

        foreach (array_values($this->input('pizza.sizes')) as $position => $size) {
            $key = isset($size['id']) ? (string) $size['id'] : 'n'.$position;
            $keys[] = $key;

            if ($size['active']) {
                $activeKeys[] = $key;
            }
        }

        if ($activeKeys === []) {
            $validator->errors()->add('pizza.sizes', 'Cadastre pelo menos um tamanho ativo.');
        }

        $resolve = fn (int|string $key): ?string => match (true) {
            in_array((string) $key, $keys, true) => (string) $key,
            in_array('n'.$key, $keys, true) => 'n'.$key,
            default => null,
        };
        $flavorGroup = $product?->optionGroups()->where('kind', OptionGroup::FLAVOR)->first();
        $flavors = array_values($this->input('pizza.flavors'));

        if (! in_array(true, array_map(fn (array $flavor) => (bool) $flavor['active'], $flavors), true)) {
            $validator->errors()->add('pizza.flavors', 'Cadastre pelo menos um sabor ativo.');
        }

        foreach ($flavors as $index => $flavor) {
            if (isset($flavor['id']) && ! $flavorGroup?->options()->whereKey($flavor['id'])->exists()) {
                $validator->errors()->add("pizza.flavors.$index.id", 'Sabor inválido.');
            }

            $priced = array_map($resolve, array_keys($flavor['prices']));

            if (in_array(null, $priced, true)) {
                $validator->errors()->add("pizza.flavors.$index.prices", 'Tamanho inválido.');
            } elseif ($flavor['active'] && array_diff($activeKeys, $priced) !== []) {
                $validator->errors()->add("pizza.flavors.$index.prices", 'Informe o preço do sabor em todos os tamanhos ativos.');
            }
        }
    }
}
