<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksCreditSale;
use App\Http\Requests\Concerns\ValidatesComposition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSaleRequest extends FormRequest
{
    use ChecksCreditSale, ValidatesComposition;

    public function rules(): array
    {
        return [
            ...$this->compositionRules('items.*'),
            'client_uuid' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('products', 'id')->where('company_id', $this->user()->company_id)->where('active', true)->where('sellable', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999'],
            'items.*.expected_price' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('company_id', $this->user()->company_id)],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(array_keys(config('sales.payment_methods')))],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'items' => 'itens',
            'items.*.product_id' => 'produto',
            'items.*.quantity' => 'quantidade',
            'discount' => 'desconto',
            'payments' => 'pagamentos',
            'payments.*.method' => 'forma de pagamento',
            'payments.*.amount' => 'valor',
            'notes' => 'observação',
        ];
    }

    public function messages(): array
    {
        return ['items.required' => 'Adicione pelo menos um produto.'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ((float) $this->input('discount') > 0 && ! $this->user()->hasPermission('sales.discount')) {
                $validator->errors()->add('discount', 'Você não tem permissão para dar desconto.');
            }

            $this->checkCredit($validator, $this->input('customer_id'));
        }];
    }
}
