<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WhatsAppSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        ];
    }

    public function isConnected(): bool
    {
        return $this->state === 'open';
    }

    protected function auditRedact(): array
    {
        return ['instance_token'];
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
