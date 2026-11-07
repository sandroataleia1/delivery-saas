<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestaurantOperationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company.manage');
    }

    public function rules(): array
    {
        $rules = ['confirmation_mode' => ['sometimes', Rule::in(['manual', 'automatic'])],
            'visual_alerts' => ['sometimes', 'boolean'], 'sound_alerts' => ['sometimes', 'boolean']];
        foreach (config('restaurant.ceilings') as $key => $max) {
            $rules[$key] = ['sometimes', str_contains($key, 'value') ? 'numeric' : 'integer', 'min:1', 'max:'.$max];
            if (str_contains($key, 'value')) {
                $rules[$key][] = 'decimal:0,2';
            }
        }
        foreach (['company_id', 'automatic_blocked', 'automatic_block_reason', 'automatic_blocked_by_id', 'automatic_blocked_at', 'effective_mode', 'effective_reason'] as $key) {
            $rules[$key] = ['prohibited'];
        }

        return $rules;
    }
}
