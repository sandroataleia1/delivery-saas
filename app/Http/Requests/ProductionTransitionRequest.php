<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductionTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->input('target') === 'delivered' ? 'production.deliver' : 'production.operate');
    }

    public function rules(): array
    {
        return ['expected_version' => ['required', 'integer', 'min:0'], 'target' => ['required', Rule::in(['preparing', 'ready', 'delivered'])]];
    }
}
