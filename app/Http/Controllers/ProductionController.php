<?php

namespace App\Http\Controllers;

use App\Actions\ReconcileProduction;
use App\Actions\TransitionProduction;
use App\Http\Requests\ProductionTransitionRequest;
use App\Models\Company;
use App\Models\ProductionEvent;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use App\Support\RestaurantOperationPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public function transition(ProductionTransitionRequest $request, ProductionWork $work, TransitionProduction $action): HttpResponse
    {
        $action->handle($work, $request->integer('expected_version'), $request->validated('target'));

        return back()->with('success', 'Etapa registrada.');
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
