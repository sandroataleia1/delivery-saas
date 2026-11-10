<?php

namespace App\Support;

use App\Models\DiningTable;
use Illuminate\Support\Collection;

/** Mapa do salão: mesas ativas por número, com a comanda aberta de cada uma. Usado pela web e pelo app. */
class TableFloor
{
    /**
     * @return Collection<int, array{id: int, number: int, area: ?string, seats: int, order: ?array{id: int, people: int, pending_count: int, pending_ids: Collection<int, int>, oldest_pending_at: ?string, waiter: string, total: string, minutes: int, closing: bool, closing_minutes: ?int}}>
     */
    public static function rows(): Collection
    {
        return DiningTable::query()
            ->where('active', true)
            ->with(['openOrder' => fn ($query) => $query->withSum('activeItems as total', 'total')->with('pendingReceipts:id,table_order_id,created_at')])
            ->orderBy('number')
            ->get()
            ->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'number' => $table->number,
                'area' => $table->area,
                'seats' => $table->seats,
                'order' => $table->openOrder ? [
                    'id' => $table->openOrder->id,
                    'people' => $table->openOrder->people,
                    'pending_count' => $table->openOrder->pendingReceipts->count(),
                    'pending_ids' => $table->openOrder->pendingReceipts->pluck('id'),
                    'oldest_pending_at' => $table->openOrder->pendingReceipts->min('created_at')?->toIso8601String(),
                    'waiter' => $table->openOrder->user_name,
                    'total' => (string) ($table->openOrder->total ?? 0),
                    'minutes' => (int) $table->openOrder->opened_at->diffInMinutes(now()),
                    'closing' => $table->openOrder->bill_requested_at !== null,
                    'closing_minutes' => $table->openOrder->bill_requested_at ? (int) $table->openOrder->bill_requested_at->diffInMinutes(now()) : null,
                ] : null,
            ]);
    }
}
