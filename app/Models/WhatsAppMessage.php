<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\WhatsAppMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    /** @use HasFactory<WhatsAppMessageFactory> */
    use BelongsToCompany, HasFactory;

    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const MAX_ATTEMPTS = 5;

    protected $table = 'whatsapp_messages';

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function onlineOrder(): BelongsTo
    {
        return $this->belongsTo(OnlineOrder::class);
    }
}
