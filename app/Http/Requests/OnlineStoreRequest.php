<?php

namespace App\Http\Requests;

use App\Models\OnlineStore;
use App\Support\BrazilPhone;
use App\Support\PublicImage;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnlineStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company.manage');
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        // FormData omite listas vazias; em multipart a ausência de `hours` significa nenhum turno.
        if (! $this->isJson() && ! $this->has('hours')) {
            $merge['hours'] = [];
        }
        if (is_string($this->input('whatsapp'))) {
            $digits = preg_replace('/\D/', '', $this->input('whatsapp'));
            // Sem o 55: o link do WhatsApp acrescenta o país. Número inválido segue para a validação recusar.
            $merge['whatsapp'] = BrazilPhone::normalize($digits) ?? ($digits ?: null);
        }
        if (is_string($this->input('min_order'))) {
            $merge['min_order'] = str_replace(',', '.', trim($this->input('min_order')));
        }
        if (is_string($this->input('slug'))) {
            $merge['slug'] = trim($this->input('slug'));
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        $own = OnlineStore::where('company_id', $this->user()->company_id)->value('id');

        return [
            'enabled' => ['required', 'boolean'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('online_stores', 'slug')->ignore($own)],
            'accepts_delivery' => ['required', 'boolean'],
            'accepts_pickup' => ['required', 'boolean'],
            'min_order' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'eta_text' => ['nullable', 'string', 'max:60'],
            'whatsapp' => ['nullable', 'digits_between:10,11'],
            'accept_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'logo' => PublicImage::RULES,
            'remove_logo' => ['sometimes', 'boolean'],
            'hours' => ['present', 'array', 'max:70'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i', 'different:hours.*.opens_at'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->boolean('enabled') && ! $this->boolean('accepts_delivery') && ! $this->boolean('accepts_pickup')) {
                $validator->errors()->add('accepts_delivery', 'Escolha entrega, retirada ou ambas para abrir a loja.');
            }
            if ($this->hasOverlap($this->input('hours', []))) {
                $validator->errors()->add('hours', 'Há turnos sobrepostos no mesmo dia.');
            }
        }];
    }

    /**
     * Cada turno vira intervalos em minutos da semana; o que passa da meia-noite continua no dia seguinte.
     *
     * @param  array<int, array{weekday: int|string, opens_at: string, closes_at: string}>  $hours
     */
    private function hasOverlap(array $hours): bool
    {
        $intervals = [];
        foreach ($hours as $shift) {
            $day = (int) $shift['weekday'];
            $opens = $this->minutes($shift['opens_at']);
            $closes = $this->minutes($shift['closes_at']);
            if ($closes > $opens) {
                $intervals[] = [$day * 1440 + $opens, $day * 1440 + $closes];

                continue;
            }
            $intervals[] = [$day * 1440 + $opens, ($day + 1) * 1440];
            $next = ($day + 1) % 7;
            $intervals[] = [$next * 1440, $next * 1440 + $closes];
        }
        foreach ($intervals as $i => [$startA, $endA]) {
            foreach (array_slice($intervals, $i + 1) as [$startB, $endB]) {
                if ($startA < $endB && $startB < $endA) {
                    return true;
                }
            }
        }

        return false;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = explode(':', $time);

        return (int) $hour * 60 + (int) $minute;
    }

    public function attributes(): array
    {
        return [
            'slug' => 'endereço da loja',
            'min_order' => 'pedido mínimo',
            'eta_text' => 'previsão',
            'whatsapp' => 'WhatsApp',
            'accept_minutes' => 'prazo para aceitar',
            'logo' => 'logo',
            'hours' => 'horários',
            'hours.*.opens_at' => 'abertura',
            'hours.*.closes_at' => 'fechamento',
        ];
    }
}
