<?php

namespace App\Http\Requests;

use App\Rules\Cnpj;
use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['document' => Cnpj::normalize($this->input('document')) ?: null]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'document' => ['nullable', 'string', 'max:14', new Cnpj],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'document' => 'CNPJ',
            'email' => 'e-mail',
            'phone' => 'telefone',
            'notes' => 'observações',
        ];
    }
}
