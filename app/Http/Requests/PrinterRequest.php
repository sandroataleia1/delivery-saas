<?php

namespace App\Http\Requests;

use App\Models\Printer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $network = $this->input('connection') === Printer::NETWORK;

        return [
            'name' => ['required', 'string', 'max:60', Rule::unique('printers')->where('company_id', $this->user()->company_id)->ignore($this->route('printer'))],
            'connection' => ['required', Rule::in([Printer::NETWORK, Printer::WINDOWS])],
            // Rede: IP ou nome do aparelho. Windows: nome da impressora instalada ou caminho \\COMPUTADOR\Compartilhamento.
            'address' => ['required', 'string', 'max:255', $network ? 'regex:/^[A-Za-z0-9.\-]+$/' : 'not_regex:/[\x00-\x1F"]/'],
            'port' => [$network ? 'required' : 'nullable', 'integer', 'between:1,65535'],
            'columns' => ['required', 'integer', Rule::in([32, 42, 48])],
            'active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['address' => 'endereço', 'port' => 'porta', 'columns' => 'largura do papel', 'connection' => 'tipo de conexão'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['address.regex' => 'Informe só o IP ou o nome da impressora na rede, sem http:// nem porta.'];
    }
}
