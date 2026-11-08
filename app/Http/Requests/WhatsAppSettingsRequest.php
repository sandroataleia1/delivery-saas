<?php

namespace App\Http\Requests;

use App\Support\WhatsAppTemplates;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class WhatsAppSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'templates' => ['nullable', 'array'],
            'templates.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $unknown = array_diff(array_keys((array) $this->input('templates', [])), array_keys(WhatsAppTemplates::EVENTS));
            if ($unknown !== []) {
                $validator->errors()->add('templates', 'Etapa de mensagem desconhecida.');
            }
        }];
    }
}
