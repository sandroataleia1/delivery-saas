<?php

namespace App\Http\Requests;

use App\Models\Bill;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillRequest extends FormRequest
{
    public function rules(): array
    {
        $bill = $this->route('bill');
        $type = $bill?->type ?? $this->route('type');
        $key = $type === 'pagar' ? Bill::PAYABLE : ($type === 'receber' ? Bill::RECEIVABLE : $type);

        return [
            'description' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(config("finance.categories.$key"))],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('company_id', $this->user()->company_id)],
            'counterparty' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'installments' => [Rule::excludeIf($bill !== null), 'nullable', 'integer', 'min:1', 'max:60'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'description' => 'descrição',
            'category' => 'categoria',
            'supplier_id' => 'fornecedor',
            'counterparty' => 'nome',
            'amount' => 'valor',
            'due_date' => 'vencimento',
            'installments' => 'parcelas',
            'notes' => 'observações',
        ];
    }
}
