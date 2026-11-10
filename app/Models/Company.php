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
            'auto_print_orders' => 'boolean',
            'auto_print_kitchen' => 'boolean',
            'print_agent_seen_at' => 'datetime',
            'print_agent_printers' => 'array',
            'print_agent_printers_at' => 'datetime',
            'print_agent_printers_requested_at' => 'datetime',
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

    /** Alguém pediu a busca de impressoras e o agente ainda não respondeu. */
    public function printerSearchPending(): bool
    {
        return $this->print_agent_printers_requested_at !== null
            && ($this->print_agent_printers_at === null || $this->print_agent_printers_at->lt($this->print_agent_printers_requested_at));
    }

    public function auditLabel(): string
    {
        return $this->trade_name;
    }

    /** O sinal de vida e a lista de impressoras do agente mudam sozinhos; a chave nunca aparece. */
    protected function auditExclude(): array
    {
        return ['print_agent_token', 'print_agent_seen_at', 'print_agent_printers', 'print_agent_printers_at', 'print_agent_printers_requested_at'];
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
