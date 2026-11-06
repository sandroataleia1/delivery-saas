<?php

namespace App\Actions;

use App\Models\CashSession;
use App\Models\Company;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fechamento às cegas: o operador informa o que contou sem ver o esperado.
 * Havendo diferença, é preciso recontar ou justificar.
 */
class CloseCashSession
{
    public function handle(CashSession $session, string $countedCash, ?string $notes): CashSession
    {
        return DB::transaction(function () use ($session, $countedCash, $notes) {
            // Mesma fila das vendas: sem isso, fechar com vendas entrando gera deadlock.
            Company::whereKey($session->company_id)->lockForUpdate()->firstOrFail();

            /** @var CashSession $locked */
            $locked = CashSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['counted_cash' => 'Este caixa já foi fechado.']);
            }

            $summary = $locked->calculateSummary();
            $difference = bcsub($countedCash, $summary['expected_cash'], 2);

            if (bccomp($difference, '0', 2) !== 0 && blank($notes)) {
                throw ValidationException::withMessages([
                    'closing_notes' => 'O valor não confere. Reconte ou explique a diferença.',
                ]);
            }

            $locked->forceFill([
                'open_user_id' => null,
                'status' => CashSession::CLOSED,
                'closed_at' => now(),
                'closed_by' => Auth::user()->name,
                'expected_cash' => $summary['expected_cash'],
                'counted_cash' => $countedCash,
                'difference' => $difference,
                'closing_notes' => $notes,
                'summary' => $summary,
            ])->save();

            return $locked;
        });
    }
}
