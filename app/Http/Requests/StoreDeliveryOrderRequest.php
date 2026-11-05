<?php

namespace App\Http\Requests;

use App\Models\DeliveryOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryOrderRequest extends FormRequest
{
    public function rules(): array
    {
        $companyId = $this->user()->company_id;

        return [
            'client_uuid' => ['required', 'uuid'],
            'customer_id' => ['required', Rule::exists('customers', 'id')->where('company_id', $companyId)],
            'type' => ['required', Rule::in([DeliveryOrder::DELIVERY, DeliveryOrder::PICKUP])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'required',
                Rule::exists('products', 'id')->where('company_id', $companyId)->where('active', true)->where('sellable', true),
            ],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999'],
            'items.*.notes' => ['nullable', 'string', 'max:120'],
            'address' => ['exclude_unless:type,delivery', 'required', 'array'],
            'address.street' => ['exclude_unless:type,delivery', 'required', 'string', 'max:255'],
            'address.number' => ['exclude_unless:type,delivery', 'required', 'string', 'max:20'],
            'address.complement' => ['nullable', 'string', 'max:100'],
            'address.district' => ['exclude_unless:type,delivery', 'required', 'string', 'max:100'],
            'address.reference' => ['nullable', 'string', 'max:150'],
            'delivery_fee' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999'],
            'payment_method' => ['required', Rule::in(array_keys(config('sales.payment_methods')))],
            'change_for' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'cliente',
            'items' => 'itens',
            'address.street' => 'rua',
            'address.number' => 'número',
            'address.district' => 'bairro',
            'delivery_fee' => 'taxa de entrega',
            'payment_method' => 'forma de pagamento',
            'change_for' => 'troco para',
            'notes' => 'observações',
        ];
    }

    public function messages(): array
    {
        return ['items.required' => 'Adicione pelo menos um produto.'];
    }
}
