<?php

namespace App\Http\Requests;

use App\Rules\Cnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Cadastro de empresa pelo super administrador. */
class CompanyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['document' => Cnpj::normalize($this->input('document'))]);
    }

    public function rules(): array
    {
        $rules = [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['required', 'string', 'max:255'],
            'document' => ['required', new Cnpj, Rule::unique('companies')->ignore($this->route('company'))],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ];

        if (! $this->route('company')) {
            $rules += [
                'admin_name' => ['required', 'string', 'max:255'],
                'admin_email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
                'admin_password' => ['required', 'confirmed', Password::defaults()],
            ];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return [
            'legal_name' => 'razão social',
            'trade_name' => 'nome fantasia',
            'document' => 'CNPJ',
            'email' => 'e-mail',
            'phone' => 'telefone',
            'admin_name' => 'nome do administrador',
            'admin_email' => 'e-mail do administrador',
            'admin_password' => 'senha provisória',
        ];
    }
}
