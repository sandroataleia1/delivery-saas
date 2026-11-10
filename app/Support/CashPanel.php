<?php

namespace App\Support;

use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;

/** O caixa aberto como aparece na tela Caixa e no PDV: resumo, lançamentos e vendas. */
class CashPanel
{
    /** Vendas mostradas no painel: as mais recentes bastam para achar a que precisa ser cancelada. */
    private const RECENT_SALES = 30;

    /**
     * @return array{id: int, number: int, opened_at: string, opening_amount: string, sales_count: int, expected_cash: string|null, movements: list<array<string, mixed>>, sales: list<array<string, mixed>>}
     */
    public static function present(CashSession $session, User $user): array
    {
        $summary = $session->calculateSummary();

        return [
            'id' => $session->id,
            'number' => $session->number,
            'opened_at' => $session->opened_at->format('d/m/Y H:i'),
            'opening_amount' => $session->opening_amount,
            'sales_count' => $summary['sales_count'],
            // Fechamento às cegas: o esperado só aparece para quem gerencia o caixa.
            'expected_cash' => $user->hasPermission('cash.manage') ? $summary['expected_cash'] : null,
            'movements' => self::movements($session),
            'sales' => self::sales($session),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function movements(CashSession $session): array
    {
        $movements = $session->movements()->latest('id')->get();
        $reversed = $movements->pluck('reverses_movement_id')->filter()->flip();
        $open = $session->isOpen();

        return $movements->map(fn (CashMovement $movement) => [
            'id' => $movement->id,
            'type' => $movement->type,
            'label' => __("cash.movements.{$movement->type}"),
            'is_inflow' => $movement->isInflow(),
            'amount' => $movement->amount,
            'reason' => $movement->reason,
            'user' => $movement->user_name,
            'date' => $movement->created_at->format('d/m H:i'),
            'reversed' => $reversed->has($movement->id),
            'reversible' => $open && isset(CashMovement::REVERSALS[$movement->type]) && ! $reversed->has($movement->id),
        ])->all();
    }

    /** @return list<array<string, mixed>> */
    private static function sales(CashSession $session): array
    {
        $methods = config('sales.payment_methods');

        return $session->sales()
            ->with('payments:id,sale_id,method')
            ->latest('id')
            ->limit(self::RECENT_SALES)
            ->get(['id', 'number', 'total', 'status', 'created_at'])
            ->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'number' => $sale->number,
                'total' => $sale->total,
                'cancelled' => $sale->isCancelled(),
                'payment' => $sale->payments->map(fn (SalePayment $payment) => $methods[$payment->method] ?? $payment->method)->unique()->join(', '),
                'date' => $sale->created_at->format('d/m H:i'),
            ])->all();
    }
}
