<?php

namespace App\Http\Controllers;

use App\Models\DiningTable;
use App\Models\TableOrder;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DiningTableController extends Controller
{
    /** Mapa do salão. */
    public function index(): Response
    {
        return Inertia::render('tables/Index', [
            'tables' => DiningTable::query()
                ->where('active', true)
                ->with(['openOrder' => fn ($query) => $query->withSum('activeItems as total', 'total')])
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
                        'waiter' => $table->openOrder->user_name,
                        'total' => (string) ($table->openOrder->total ?? 0),
                        'minutes' => (int) $table->openOrder->opened_at->diffInMinutes(now()),
                    ] : null,
                ]),
            'completed' => session('completed_sale'),
        ]);
    }

    public function manage(): Response
    {
        return Inertia::render('tables/Manage', [
            'tables' => DiningTable::query()->orderBy('number')->get(['id', 'number', 'area', 'seats', 'active']),
            'nextNumber' => (int) DiningTable::max('number') + 1,
        ]);
    }

    public function qrCodes(Request $request): Response
    {
        return Inertia::render('tables/QrCodes', [
            'tables' => DiningTable::query()
                ->where('active', true)
                ->orderBy('number')
                ->get()
                ->map(fn (DiningTable $table) => [
                    'id' => $table->id,
                    'number' => $table->number,
                    'url' => route('menu.show', $table->qr_token),
                ]),
            'company' => $request->user()->company->trade_name,
            'isLocal' => in_array($request->getHost(), ['127.0.0.1', 'localhost'], true),
        ]);
    }

    /** Invalida o código impresso, por exemplo se circular uma foto dele. */
    public function regenerateQr(DiningTable $table): RedirectResponse
    {
        $table->forceFill(['qr_token' => Str::random(32)])->saveQuietly();

        Audit::record('qr_regenerated', $table, "QR Code da mesa {$table->number} trocado");

        return back()->with('success', 'Novo QR Code gerado. Imprima e troque o da mesa.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'integer', 'min:1', 'max:9999'],
            'until' => ['nullable', 'integer', 'gte:number', 'max:9999'],
            'area' => ['nullable', 'string', 'max:40'],
            'seats' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['number' => 'número', 'until' => 'até o número', 'area' => 'área', 'seats' => 'lugares']);

        $numbers = range($data['number'], $data['until'] ?? $data['number']);

        if (DiningTable::whereIn('number', $numbers)->exists()) {
            return back()->withErrors(['number' => 'Já existe mesa com um desses números.']);
        }

        foreach ($numbers as $number) {
            DiningTable::create(['number' => $number, 'area' => $data['area'] ?? null, 'seats' => $data['seats']])
                ->forceFill(['active' => true])
                ->saveQuietly();
        }

        return back()->with('success', count($numbers) > 1 ? count($numbers).' mesas criadas.' : 'Mesa criada.');
    }

    public function update(Request $request, DiningTable $table): RedirectResponse
    {
        $table->update($request->validate([
            'number' => [
                'required', 'integer', 'min:1', 'max:9999',
                Rule::unique('dining_tables')->where('company_id', $request->user()->company_id)->ignore($table),
            ],
            'area' => ['nullable', 'string', 'max:40'],
            'seats' => ['required', 'integer', 'min:1', 'max:100'],
        ], [], ['number' => 'número', 'area' => 'área', 'seats' => 'lugares']));

        return back()->with('success', 'Mesa atualizada.');
    }

    public function toggleStatus(DiningTable $table): RedirectResponse
    {
        if ($table->active && TableOrder::where('open_table_id', $table->id)->exists()) {
            return back()->with('error', 'Esta mesa está ocupada. Feche a conta antes de inativar.');
        }

        $table->active = ! $table->active;
        $table->save();

        return back()->with('success', $table->active ? 'Mesa ativada.' : 'Mesa inativada.');
    }
}
