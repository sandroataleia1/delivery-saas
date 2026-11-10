<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um papel a imprimir: os bytes ESC/POS já prontos, esperando o agente local buscar. */
class PrintJob extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const PRINTED = 'printed';

    public const FAILED = 'failed';

    /** Depois disso o trabalho para de ser tentado e aparece como falha na tela. */
    public const MAX_ATTEMPTS = 5;

    /** Tempo que um trabalho entregue ao agente fica reservado antes de poder ser entregue de novo. */
    public const CLAIM_SECONDS = 60;

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'printed_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }
}
