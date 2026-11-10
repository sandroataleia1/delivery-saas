<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Option;
use Illuminate\Support\Facades\DB;

/** Pausa ou retoma uma opção. Pausada, ela é recusada em pedidos novos. */
class PauseOption
{
    public function handle(Option $option, bool $paused): void
    {
        DB::transaction(function () use ($option, $paused) {
            Company::whereKey($option->company_id)->lockForUpdate()->firstOrFail();
            $locked = Option::whereKey($option->id)->lockForUpdate()->firstOrFail();
            $locked->paused = $paused;
            $locked->save();
        });

        $option->refresh();
    }
}
