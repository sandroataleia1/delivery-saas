<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Preço da pizza inteira de um sabor em um tamanho. Auditado em bloco por SavePizzaSetup. */
class OptionPrice extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price' => 'decimal:2'];
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(Option::class);
    }

    public function pizzaSize(): BelongsTo
    {
        return $this->belongsTo(PizzaSize::class);
    }
}
