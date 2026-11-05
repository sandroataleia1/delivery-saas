<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Rules\Cnpj;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Cadastro público: cria a empresa e seu primeiro administrador. */
class RegisterRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'document' => Cnpj::normalize($this->input('document')),
            'zip_code' => preg_replace('/\D/', '', (string) $this->input('zip_code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'owner_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_phone' => ['required', 'string', 'min:14', 'max:20'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'document' => ['required', new Cnpj, Rule::unique('companies', 'document')],
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'min:14', 'max:20'],
            ...Company::profileRules('required'),
        ];
    }

    public function attributes(): array
    {
        return [
            'owner_name' => 'nome',
            'email' => 'e-mail',
            'owner_phone' => 'celular',
            'password' => 'senha',
            'document' => 'CNPJ',
            'legal_name' => 'razão social',
            'trade_name' => 'nome da loja',
            'phone' => 'telefone da loja',
            ...Company::profileAttributes(),
        ];
    }
}
