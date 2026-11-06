<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksCreditSale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SettleDeliveryOrderRequest extends FormRequest
{
    use ChecksCreditSale;

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'discount' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'payments' => ['present', 'array', 'max:10'],
            'payments.*.method' => ['required', Rule::in(array_keys(config('sales.payment_methods')))],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999'],
        ];
    }

    public function attributes(): array
    {
        return ['discount' => 'desconto', 'payments' => 'pagamentos', 'payments.*.amount' => 'valor'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ((float) $this->input('discount') > 0 && ! $this->user()->hasPermission('sales.discount')) {
                $validator->errors()->add('discount', 'Você não tem permissão para dar desconto.');
            }

            $this->checkCredit($validator, $this->route('delivery')?->customer_id);
        }];
    }
}
