<?php

namespace App\Actions;

use App\Models\CashSession;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpenCashSession
{
    public function handle(User $user, string $openingAmount): CashSession
    {
        return DB::transaction(function () use ($user, $openingAmount) {
            Company::whereKey($user->company_id)->lockForUpdate()->firstOrFail();

            if (CashSession::query()->openFor($user)->exists()) {
                throw ValidationException::withMessages(['opening_amount' => 'Você já tem um caixa aberto.']);
            }

            $session = new CashSession;
            $session->forceFill([
                'company_id' => $user->company_id,
                'number' => (int) CashSession::max('number') + 1,
                'open_user_id' => $user->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'opening_amount' => $openingAmount,
                'status' => CashSession::OPEN,
                'opened_at' => now(),
            ])->save();

            return $session;
        });
    }
}
