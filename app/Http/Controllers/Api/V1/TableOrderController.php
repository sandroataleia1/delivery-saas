<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\OpenTable;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TableOrderResource;
use App\Models\DiningTable;
use App\Models\TableOrder;
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

    private function ensureOpen(TableOrder $order): void
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
        }
    }
}
