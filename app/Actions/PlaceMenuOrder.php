<?php

namespace App\Actions;

use App\Models\DiningTable;
use App\Models\TableOrder;
use Illuminate\Validation\ValidationException;

/** Pedido do cliente pelo QR Code: lança na comanda da mesa, abrindo-a se preciso. */
class PlaceMenuOrder
{
    public const GUEST = 'Cliente (QR Code)';

    public function __construct(
        private OpenTable $openTable,
        private AddOrderItems $addItems,
    ) {}

    public function handle(DiningTable $table, array $items, string $clientUuid, ?int $knownOrderId): void
    {
        $order = $this->openOrder($table);

        // Página aberta desde a conta anterior: o pedido cairia na conta de outra pessoa.
        if ($knownOrderId !== null && $order?->id !== $knownOrderId) {
            throw ValidationException::withMessages(['items' => 'Esta conta já foi encerrada. Escaneie o QR Code da mesa de novo.']);
        }

        $this->addItems->handle($order ?? $this->openOrJoin($table), $items, $clientUuid, self::GUEST);
    }

    /** Amigos pedindo juntos: quem chega por último entra na comanda que o outro abriu. */
    private function openOrJoin(DiningTable $table): TableOrder
    {
        try {
            return $this->openTable->handle($table, 1, null, self::GUEST);
        } catch (ValidationException $exception) {
            return $this->openOrder($table) ?? throw $exception;
        }
    }

    private function openOrder(DiningTable $table): ?TableOrder
    {
        return TableOrder::withoutGlobalScopes()->where('open_table_id', $table->id)->first();
    }
}
