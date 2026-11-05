<?php

namespace App\Http\Requests;

use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/** Dados que a própria empresa pode editar. O CNPJ fica de fora. */
class CompanyProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['zip_code' => preg_replace('/\D/', '', (string) $this->input('zip_code')) ?: null]);
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'service_fee_percent' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:30'],
            ...Company::profileRules('nullable'),
        ];
    }

    public function attributes(): array
    {
        return [
            'legal_name' => 'razão social',
            'trade_name' => 'nome fantasia',
            'email' => 'e-mail',
            'phone' => 'telefone',
            'service_fee_percent' => 'taxa de serviço',
            ...Company::profileAttributes(),
        ];
    }
}
