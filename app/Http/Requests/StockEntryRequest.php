<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockEntryRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $companyId)],
            'invoice_number' => ['nullable', 'string', 'max:44'],
            'entry_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
            'generate_bill' => ['sometimes', 'boolean'],
            'bill_due_date' => ['required_if_accepted:generate_bill', 'nullable', 'date_format:Y-m-d'],
            'bill_installments' => ['nullable', 'integer', 'min:1', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => [
                'required',
                'distinct',
                Rule::exists('products', 'id')->where('company_id', $companyId)->where('track_stock', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:9999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'fornecedor',
            'invoice_number' => 'número da nota',
            'entry_date' => 'data da entrada',
            'notes' => 'observações',
            'bill_due_date' => 'vencimento',
            'bill_installments' => 'parcelas',
            'items' => 'itens',
            'items.*.product_id' => 'produto',
            'items.*.quantity' => 'quantidade',
            'items.*.unit_cost' => 'custo unitário',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Adicione pelo menos um produto.',
            'bill_due_date.required_if_accepted' => 'Informe o vencimento da conta a pagar.',
        ];
    }
}
