<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesComposition;
use Illuminate\Foundation\Http\FormRequest;

class StoreMenuOrderRequest extends FormRequest
{
    use ValidatesComposition;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::payloadRules();
    }

    public static function payloadRules(): array
    {
        return ['client_uuid' => ['required', 'uuid'], 'service_context' => ['required', 'string', 'size:64'],
            'tracking_key' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['bail', 'required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.notes' => ['nullable', 'string', 'max:120'],
            ...(new self)->compositionRules('items.*')];
    }
}
