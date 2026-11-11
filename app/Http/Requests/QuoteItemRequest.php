<?php

namespace App\Http\Requests;

/** Um item montado (pizza, adicionais) para o app saber o preço antes de pôr no carrinho. Nada é gravado. */
class QuoteItemRequest extends AddOrderItemsRequest
{
    public function rules(): array
    {
        return $this->itemRules('');
    }

    protected function isReplay(): bool
    {
        return false;
    }

    protected function productFields(): array
    {
        return ['product_id' => $this->input('product_id')];
    }
}
