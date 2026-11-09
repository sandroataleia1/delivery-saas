<?php

namespace App\Http\Requests;

use App\Support\BrazilPhone;
use App\Support\WhatsAppTemplates;
use Closure;
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
            'auto_reply' => ['sometimes', 'boolean'],
            'greeting' => ['nullable', 'string', 'max:1000'],
            'ignored_numbers' => ['sometimes', 'nullable', 'array', 'max:100'],
            'ignored_numbers.*' => ['required', 'string', 'max:30', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || BrazilPhone::normalize($value) === null) {
                    $fail('Número inválido: '.(is_string($value) ? $value : ''));
                }
            }],
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
