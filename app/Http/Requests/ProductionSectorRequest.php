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
        return ['name' => ['required', 'string', 'max:60', Rule::unique('production_sectors')->where('company_id', $this->user()->company_id)->ignore($this->route('sector'))], 'active' => ['required', 'boolean'], 'is_default' => ['sometimes', 'boolean']];
    }
}
