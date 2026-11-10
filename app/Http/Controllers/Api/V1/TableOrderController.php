<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddOrderItemsRequest;
use App\Http\Requests\QuoteItemRequest;
use App\Http\Resources\V1\TableOrderResource;
use App\Models\DiningTable;
use App\Models\TableOrder;
use App\Support\ItemComposer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Comanda da mesa no app do garçom. Usa as mesmas Actions da tela de mesas da web. */
class TableOrderController extends Controller
{
    public function open(Request $request, DiningTable $table, OpenTable $openTable): JsonResponse
    {
        $data = $request->validate(
            ['people' => ['required', 'integer', 'min:1', 'max:100']],
            [],
            ['people' => 'pessoas'],
        );

        $order = $openTable->handle($table, $data['people'], $request->user());

        return TableOrderResource::make($order)->response()->setStatusCode(201);
    }

    public function show(TableOrder $order): TableOrderResource
    {
        $this->ensureOpen($order);

        return TableOrderResource::make($order);
    }

    /** Lança e devolve a comanda atualizada. Reenvio com o mesmo client_uuid não duplica. */
    public function addItems(AddOrderItemsRequest $request, TableOrder $order, AddOrderItems $addItems): JsonResponse
    {
        $data = $request->validated();

        $addItems->handle($order, $data['items'], $data['client_uuid']);

        return TableOrderResource::make($order->fresh())->response()->setStatusCode(201);
    }

    /**
     * Preço e descrição de um item montado, calculados pelo servidor, sem gravar nada.
     *
     * @return array{unit_price: string, options_label: ?string}
     */
    public function quote(QuoteItemRequest $request, TableOrder $order, ItemComposer $composer): array
    {
        $this->ensureOpen($order);

        $composed = $composer->composeMany($order->company_id, [$request->validated()])[0];

        return ['unit_price' => $composed['unit_price'], 'options_label' => $composed['options_label']];
    }

    private function ensureOpen(TableOrder $order): void
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
        }
    }
}
