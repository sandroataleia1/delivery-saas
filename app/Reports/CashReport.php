<?php

namespace App\Reports;

use App\Models\CashMovement;
use App\Models\CashSession;

class CashReport
{
    public function __construct(private Period $period) {}

    public function data(): array
    {
        $sessions = CashSession::query()
            ->where('status', CashSession::CLOSED)
            ->whereBetween('closed_at', $this->period->range())
            ->latest('closed_at')
            ->get(['id', 'number', 'user_id', 'user_name', 'closed_at', 'expected_cash', 'counted_cash', 'difference', 'closing_notes']);

        $movements = CashMovement::query()
            ->whereBetween('created_at', $this->period->range())
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'summary' => [
                'sessions' => $sessions->count(),
                'with_difference' => $sessions->filter(fn (CashSession $session) => (float) $session->difference !== 0.0)->count(),
                'short' => Money::of($sessions->filter(fn (CashSession $session) => $session->difference < 0)->sum('difference')),
                'over' => Money::of($sessions->filter(fn (CashSession $session) => $session->difference > 0)->sum('difference')),
                'withdrawals' => Money::of(CashMovement::netTotals($movements)['withdrawals']),
                'deposits' => Money::of(CashMovement::netTotals($movements)['deposits']),
            ],
            // Mesmo operador sempre com falta é o sinal que este relatório existe para mostrar.
            'operators' => $sessions->groupBy('user_name')
                ->map(fn ($group, string $name) => [
                    'label' => $name,
                    'sessions' => $group->count(),
                    'with_difference' => $group->filter(fn (CashSession $session) => (float) $session->difference !== 0.0)->count(),
                    'total' => Money::of($group->sum('difference')),
                ])
                ->sortBy('total')
                ->values()
                ->all(),
            'sessions' => $sessions
                ->filter(fn (CashSession $session) => (float) $session->difference !== 0.0)
                ->take(50)
                ->map(fn (CashSession $session) => [
                    'id' => $session->id,
                    'number' => $session->number,
                    'user' => $session->user_name,
                    'date' => $session->closed_at->format('d/m/Y H:i'),
                    'expected' => $session->expected_cash,
                    'counted' => $session->counted_cash,
                    'difference' => $session->difference,
                    'notes' => $session->closing_notes,
                ])
                ->values()
                ->all(),
        ];
    }
}
