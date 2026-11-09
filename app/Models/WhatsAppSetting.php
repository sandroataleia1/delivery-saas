<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WhatsAppSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class WhatsAppSetting extends Model
{
    /** @use HasFactory<WhatsAppSettingFactory> */
    use Auditable, BelongsToCompany, HasFactory;

    protected $table = 'whatsapp_settings';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'instance_token' => 'encrypted',
            'templates' => 'array',
            'state_checked_at' => 'datetime',
            'webhook_secret' => 'encrypted',
            'auto_reply' => 'boolean',
            'ignored_numbers' => 'array',
        ];
    }

    public function isConnected(): bool
    {
        return $this->state === 'open';
    }

    /** Endereço do webhook desta instância; cria o segredo na primeira vez. */
    public function webhookUrl(): string
    {
        if (blank($this->webhook_secret)) {
            $this->forceFill(['webhook_secret' => Str::random(40)])->save();
        }

        return route('whatsapp.webhook', [$this->instance_name, $this->webhook_secret]);
    }

    protected function auditRedact(): array
    {
        return ['instance_token', 'webhook_secret'];
    }

    protected function auditExclude(): array
    {
        return ['state', 'state_checked_at'];
    }

    public function auditLabel(): string
    {
        return 'WhatsApp da loja';
    }
}
