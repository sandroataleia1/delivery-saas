<?php

namespace App\Http\Controllers;

use App\Actions\ReconcileProduction;
use App\Actions\TransitionProduction;
use App\Http\Requests\ProductionTransitionRequest;
use App\Models\Company;
use App\Models\ProductionEvent;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Support\Printing\PrintQueue;
use App\Support\RestaurantOperationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ProductionController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = $request->validate(['sector' => ['nullable', 'integer', Rule::exists('production_sectors', 'id')->where('company_id', $request->user()->company_id)]]);
        $works = ProductionWork::whereNull('delivered_at')->whereNull('cancelled_at')->when($filter['sector'] ?? null, fn ($q, $id) => $q->where('production_sector_id', $id))->orderBy('created_at')->orderBy('id')->get();
        $notices = ProductionEvent::where('to_state', 'cancelled')->whereNull('acknowledged_at')->whereIn('production_work_id', ProductionWork::when($filter['sector'] ?? null, fn ($q, $id) => $q->where('production_sector_id', $id))->select('id'))->orderBy('id')->get();
        $cancelled = ProductionWork::whereIn('id', $notices->pluck('production_work_id'))->get()->keyBy('id');

        return Inertia::render('production/Index', ['operation' => array_intersect_key((new RestaurantOperationPolicy)->resolve($request->user()->company), array_flip(['sound_alerts', 'late_after_minutes'])), 'works' => $works->map(fn ($w) => $w->only('id', 'production_sector_id', 'sector_name', 'product_name', 'options_label', 'unit', 'quantity', 'notes', 'origin_label', 'status', 'version', 'legacy', 'reconciled_at', 'created_at', 'table_order_item_id')),
            'notices' => $notices->map(fn ($e) => ['id' => $e->id, 'reason' => $e->reason, 'created_at' => $e->created_at,
                'item' => $cancelled[$e->production_work_id]->only('product_name', 'options_label', 'quantity', 'unit', 'sector_name', 'origin_label')]),
            'sectors' => ProductionSector::orderBy('name')->get(['id', 'name', 'active']), 'filters' => $filter]);
    }

    /**
     * Painel de tela cheia de um setor (ex.: TV da cozinha): só o que ainda falta preparar
     * naquele setor, agrupado por pedido, do mais antigo para o mais novo.
     */
    public function panel(Request $request): Response
    {
        $filter = $request->validate(['setor' => ['nullable', 'integer', Rule::exists('production_sectors', 'id')->where('company_id', $request->user()->company_id)]], [], ['setor' => 'setor']);
        $sector = isset($filter['setor']) ? ProductionSector::findOrFail($filter['setor']) : null;

        $orders = $sector === null ? collect() : ProductionWork::query()
            ->where('production_sector_id', $sector->id)
            ->whereIn('status', ['waiting', 'preparing'])
            ->whereNull('delivered_at')->whereNull('cancelled_at')
            ->where(fn ($query) => $query->where('legacy', false)->orWhereNotNull('reconciled_at'))
            ->orderBy('created_at')->orderBy('id')
            ->get()
            ->groupBy('origin_label')
            ->map(fn ($works, string $origin) => [
                'origin' => $origin,
                'created_at' => $works->first()->created_at,
                'items' => $works->map(fn (ProductionWork $work) => $work->only('id', 'product_name', 'options_label', 'unit', 'quantity', 'notes', 'status', 'version', 'created_at'))->values()->all(),
            ])
            ->values();

        return Inertia::render('production/Panel', [
            'sector' => $sector?->only('id', 'name'),
            'sectors' => ProductionSector::where('active', true)->orderBy('name')->get(['id', 'name']),
            'orders' => $orders,
            'operation' => array_intersect_key((new RestaurantOperationPolicy)->resolve($request->user()->company), array_flip(['sound_alerts', 'late_after_minutes'])),
        ]);
    }

    /** "Tudo pronto" de um pedido no painel: leva cada item até pronto, ou não muda nenhum. */
    public function panelReady(Request $request, TransitionProduction $action): HttpResponse
    {
        $data = $request->validate([
            'works' => ['required', 'array', 'min:1', 'max:100'],
            'works.*.id' => ['required', 'integer', 'distinct', Rule::exists('production_works', 'id')->where('company_id', $request->user()->company_id)],
            'works.*.version' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $action) {
            foreach ($data['works'] as $line) {
                $work = ProductionWork::findOrFail($line['id']);
                $version = (int) $line['version'];
                if ($work->status === 'waiting') {
                    $action->handle($work, $version++, 'preparing');
                }
                $action->handle($work, $version, 'ready');
            }
        });

        return back()->with('success', 'Pedido pronto.');
    }

    public function transition(ProductionTransitionRequest $request, ProductionWork $work, TransitionProduction $action): HttpResponse
    {
        $action->handle($work, $request->integer('expected_version'), $request->validated('target'));

        return back()->with('success', 'Etapa registrada.');
    }

    /** Imprime a comanda do pedido e setor deste item, com tudo o que ainda está em preparo. */
    public function print(ProductionWork $work, PrintQueue $printQueue): HttpResponse
    {
        $works = ProductionWork::query()
            ->where('production_sector_id', $work->production_sector_id)
            ->where('origin_label', $work->origin_label)
            ->whereNull('delivered_at')->whereNull('cancelled_at')
            ->orderBy('id')
            ->get();

        if ($printQueue->queueKitchen($works->isEmpty() ? collect([$work]) : $works) === 0) {
            throw ValidationException::withMessages(['print' => "O setor {$work->sector_name} não tem impressora ativa. Configure em Impressoras."]);
        }

        return back()->with('success', 'Comanda enviada para a impressora.');
    }

    public function reconcile(Request $request, ProductionWork $work, ReconcileProduction $action): HttpResponse
    {
        $data = $request->validate(['observed' => ['required', Rule::in(['waiting', 'preparing', 'ready', 'delivered'])], 'reason' => ['required', 'string', 'min:3', 'max:255']]);
        $action->handle($work, $data['observed'], $data['reason']);

        return back()->with('success', 'Estágio observado registrado, sem inferir horários históricos.');
    }

    public function acknowledge(Request $request, ProductionEvent $event): HttpResponse
    {
        DB::transaction(function () use ($request, $event) {
            Company::whereKey($event->company_id)->lockForUpdate()->firstOrFail();
            $locked = ProductionEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->to_state === 'cancelled', 422);
            if (! $locked->acknowledged_at) {
                $locked->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $request->user()->id])->save();
            }
        });

        return back()->with('success', 'Ciência registrada.');
    }
}
