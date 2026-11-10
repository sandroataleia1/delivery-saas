<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

#[Fillable([
    'legal_name', 'trade_name', 'document', 'segment', 'email', 'phone',
    'zip_code', 'street', 'number', 'complement', 'district', 'city', 'state', 'service_fee_percent', 'qr_menu_enabled',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Auditable, HasFactory;

    /** @var list<string> */
    protected $hidden = ['print_agent_token'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'service_fee_percent' => 'decimal:2',
            'qr_menu_enabled' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function auditCompanyId(): ?int
    {
        return $this->getKey();
    }

    public function auditLabel(): string
    {
        return $this->trade_name;
    }

    /** O sinal de vida do agente de impressão muda a cada poucos segundos e a chave nunca aparece. */
    protected function auditExclude(): array
    {
        return ['print_agent_token', 'print_agent_seen_at'];
    }

    /** Regras de especialidade e endereço, iguais no cadastro e na edição. */
    public static function profileRules(string $presence): array
    {
        return [
            'segment' => [$presence, Rule::in(config('company.segments'))],
            'zip_code' => [$presence, 'digits:8'],
            'street' => [$presence, 'string', 'max:255'],
            'number' => [$presence, 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:100'],
            'district' => [$presence, 'string', 'max:100'],
            'city' => [$presence, 'string', 'max:100'],
            'state' => [$presence, Rule::in(config('company.states'))],
        ];
    }

    public static function profileAttributes(): array
    {
        return [
            'segment' => 'especialidade',
            'zip_code' => 'CEP',
            'street' => 'rua',
            'number' => 'número',
            'complement' => 'complemento',
            'district' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
        ];
    }
}
