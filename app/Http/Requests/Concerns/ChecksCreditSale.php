<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Validator;

/** Fiado exige permissão e cliente. As regras de limite ficam em RegisterSale. */
trait ChecksCreditSale
{
    protected function checkCredit(Validator $validator, mixed $customerId): void
    {
        $usesCredit = collect($this->input('payments', []))->contains('method', 'fiado');

        if (! $usesCredit) {
            return;
        }

        if (! $this->user()->hasPermission('sales.credit')) {
            $validator->errors()->add('payments', 'Você não tem permissão para vender fiado.');
        } elseif (! $customerId) {
            $validator->errors()->add('payments', 'Selecione o cliente para vender fiado.');
        }
    }
}
