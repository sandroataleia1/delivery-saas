<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddOrderItems;
use App\Actions\OpenTable;
use App\Actions\RequestBill;
use App\Actions\TransferTable;
use App\Actions\TransitionProduction;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddOrderItemsRequest;
use App\Http\Requests\QuoteItemRequest;
use App\Http\Resources\V1\TableOrderResource;
use App\Models\DiningTable;
use App\Models\TableOrder;
use App\Models\TableOrderItem;
use App\Support\ItemComposer;
use App\Support\Printing\PrintQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    /** O garçom avisa o caixa que a mesa pediu a conta (ou desfaz o aviso). */
    public function requestBill(Request $request, TableOrder $order, RequestBill $requestBill): TableOrderResource
    {
        $requested = $request->validate(['requested' => ['required', 'boolean']], [], ['requested' => 'conta pedida'])['requested'];

        $requestBill->handle($order, (bool) $requested);

        return TableOrderResource::make($order->fresh());
    }

    /** Troca a comanda de mesa; quem troca passa a ser o responsável. */
    public function transfer(Request $request, TableOrder $order, TransferTable $transferTable): TableOrderResource
    {
        $data = $request->validate([
            'dining_table_id' => [
                'required',
                Rule::exists('dining_tables', 'id')->where('company_id', $request->user()->company_id)->where('active', true),
            ],
        ], [], ['dining_table_id' => 'mesa']);

        $transferTable->handle($order, DiningTable::findOrFail($data['dining_table_id']));

        return TableOrderResource::make($order->fresh());
    }

    /**
     * Registra que os itens prontos foram levados à mesa. Item já entregue é ignorado (toque repetido ou
     * outro garçom chegou antes); item que ainda não ficou pronto recusa tudo.
     */
    public function deliver(Request $request, TableOrder $order, TransitionProduction $transition): TableOrderResource
    {
        $ids = $request->validate([
            'item_ids' => ['required', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'integer', 'distinct'],
        ])['item_ids'];
        $this->ensureOpen($order);

        $items = TableOrderItem::where('table_order_id', $order->id)->whereIn('id', $ids)->with('production')->orderBy('id')->get();
        abort_if($items->count() !== count($ids), 404);

        DB::transaction(function () use ($items, $transition) {
            foreach ($items as $item) {
                $work = $item->production;
                if ($work?->delivered_at !== null) {
                    continue;
                }
                if ($work === null || $item->isCancelled() || $work->cancelled_at !== null || $work->status !== 'ready') {
                    throw ValidationException::withMessages(['item_ids' => "{$item->product_name} ainda não está pronto."]);
                }
                $transition->handle($work, $work->version, 'delivered');
            }
        });

        return TableOrderResource::make($order->fresh());
    }

    /** Imprime a pré-conta para o cliente conferir. A mesa continua aberta; o pagamento é no caixa. */
    public function preBill(TableOrder $order, PrintQueue $printQueue): JsonResponse
    {
        $this->ensureOpen($order);

        if (! $order->activeItems()->exists()) {
            throw ValidationException::withMessages(['order' => 'Não há itens na conta.']);
        }

        if (! $printQueue->queueBill($order)) {
            throw ValidationException::withMessages(['order' => 'Não há impressora ativa para a conta. Configure em Impressoras.']);
        }

        return response()->json(['queued' => true], 202);
    }

    private function ensureOpen(TableOrder $order): void
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['order' => 'Esta comanda já foi fechada.']);
        }
    }
}
