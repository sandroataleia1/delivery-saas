<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesComposition;
use App\Models\Company;
use App\Models\DeliveryOrder;
use App\Models\OnlineStore;
use App\Support\BrazilPhone;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnlineOrderRequest extends FormRequest
{
    use ValidatesComposition;

    private ?OnlineStore $onlineStore = null;

    /** A loja é resolvida antes da validação: loja inexistente, desligada ou de empresa inativa dá 404. */
    public function authorize(): bool
    {
        $this->onlineStore();

        return true;
    }

    public function onlineStore(): OnlineStore
    {
        if ($this->onlineStore === null) {
            $store = OnlineStore::withoutGlobalScopes()->where('slug', (string) $this->route('slug'))->where('enabled', true)->first();
            abort_unless($store && Company::whereKey($store->company_id)->where('active', true)->exists(), 404);
            $this->onlineStore = $store;
        }

        return $this->onlineStore;
    }

    public function rules(): array
    {
        return self::payloadRules();
    }

    /**
     * Do navegador só chegam ids, quantidades e textos; preços e taxas saem do servidor.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function payloadRules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            'type' => ['required', Rule::in([DeliveryOrder::DELIVERY, DeliveryOrder::PICKUP])],
            'customer' => ['required', 'array'],
            'customer.name' => ['required', 'string', 'min:2', 'max:80'],
            'customer.phone' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || BrazilPhone::normalize($value) === null) {
                    $fail('Informe um WhatsApp com DDD.');
                }
            }],
            'address' => ['exclude_unless:type,'.DeliveryOrder::DELIVERY, 'required', 'array'],
            'address.street' => ['exclude_unless:type,'.DeliveryOrder::DELIVERY, 'required', 'string', 'max:120'],
            'address.number' => ['exclude_unless:type,'.DeliveryOrder::DELIVERY, 'required', 'string', 'max:20'],
            'address.complement' => ['exclude_unless:type,'.DeliveryOrder::DELIVERY, 'nullable', 'string', 'max:100'],
            'address.reference' => ['exclude_unless:type,'.DeliveryOrder::DELIVERY, 'nullable', 'string', 'max:150'],
            'address.delivery_zone_id' => ['exclude_unless:type,'.DeliveryOrder::DELIVERY, 'required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:'.config('online.max_lines')],
            'items.*.product_id' => ['bail', 'required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.config('online.max_line_quantity')],
            'items.*.notes' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['required', Rule::in(config('online.payment_methods'))],
            'change_for' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999'],
            'notes' => ['nullable', 'string', 'max:255'],
            ...(new self)->compositionRules('items.*'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['address.street' => 'rua', 'address.number' => 'número', 'address.delivery_zone_id' => 'bairro', 'customer.name' => 'nome'];
    }
}
