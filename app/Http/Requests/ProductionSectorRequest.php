<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductionSectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('production.configure');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('production_sectors')->where('company_id', $this->user()->company_id)->ignore($this->route('sector'))],
            'active' => ['required', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('company_id', $this->user()->company_id)],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'category_ids.*' => 'categoria'];
    }
}
