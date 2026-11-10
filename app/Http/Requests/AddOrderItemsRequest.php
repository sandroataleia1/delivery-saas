<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesComposition;
use App\Models\Product;
use App\Models\TableOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Lançar itens numa comanda de mesa. Usado pela web e pelo app do garçom, para as regras serem as mesmas.
 * Preço, adicionais e pizza são conferidos depois por ItemComposer.
 */
class AddOrderItemsRequest extends FormRequest
{
    use ValidatesComposition;

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            ...$this->itemRules('items.*'),
        ];
    }

    public function attributes(): array
    {
        return [
            'items' => 'itens',
            'items.*.product_id' => 'produto',
            'items.*.quantity' => 'quantidade',
            'items.*.notes' => 'observação',
            'product_id' => 'produto',
            'quantity' => 'quantidade',
            'notes' => 'observação',
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->rejectSoldOut($validator)];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function itemRules(string $prefix): array
    {
        $prefix = $prefix === '' ? '' : rtrim($prefix, '.').'.';

        return [
            "{$prefix}product_id" => [
                'bail',
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $this->user()->company_id)->where('active', true)->where('sellable', true),
            ],
            "{$prefix}quantity" => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999'],
            "{$prefix}notes" => ['nullable', 'string', 'max:120'],
            ...$this->compositionRules($prefix),
        ];
    }

    /**
     * Campo do produto de cada item lançado, para a checagem de esgotado.
     *
     * @return array<string, mixed>
     */
    protected function productFields(): array
    {
        $fields = [];
        foreach ((array) $this->input('items', []) as $index => $item) {
            $fields["items.{$index}.product_id"] = is_array($item) ? ($item['product_id'] ?? null) : null;
        }

        return $fields;
    }

    /** Reenvio de um pedido que já foi gravado nesta comanda. */
    protected function isReplay(): bool
    {
        $order = $this->route('order');

        return $order instanceof TableOrder
            && is_string($this->input('client_uuid'))
            && $order->items()->where('client_uuid', $this->input('client_uuid'))->exists();
    }

    /**
     * Produto que esgotou depois que o cardápio foi aberto: avisa pelo nome, sem lançar nada.
     * Reenvio de um pedido já gravado (mesmo client_uuid) passa, para a Action devolver o que já foi lançado.
     */
    private function rejectSoldOut(Validator $validator): void
    {
        if ($this->isReplay()) {
            return;
        }

        $fields = array_filter($this->productFields(), fn ($id, string $field) => is_numeric($id) && ! $validator->errors()->has($field), ARRAY_FILTER_USE_BOTH);

        if ($fields === []) {
            return;
        }

        $soldOut = Product::query()->whereIn('id', array_values($fields))->where('sold_out', true)->pluck('name', 'id');

        foreach ($fields as $field => $id) {
            if ($soldOut->has((int) $id)) {
                $validator->errors()->add($field, "{$soldOut[(int) $id]} está esgotado.");
            }
        }
    }
}
