<?php

namespace App\Http\Controllers;

use App\Http\Requests\PrinterRequest;
use App\Models\Company;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\ProductionSector;
use App\Support\Printing\PrintQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** Impressoras térmicas da loja, onde cada papel sai e o agente que imprime. */
class PrinterController extends Controller
{
    /** Sem sinal do agente por mais que isso, a tela mostra que ele está desligado. */
    private const AGENT_ONLINE_SECONDS = 30;

    public function index(Request $request): Response
    {
        $company = Company::findOrFail($request->user()->company_id);

        return Inertia::render('printers/Index', [
            'printers' => Printer::orderBy('name')->get(['id', 'name', 'connection', 'address', 'port', 'columns', 'active']),
            'sectors' => ProductionSector::orderBy('name')->get(['id', 'name', 'active', 'printer_id']),
            'orderPrinterId' => $company->order_printer_id,
            'billPrinterId' => $company->bill_printer_id,
            'autoPrint' => ['orders' => $company->auto_print_orders, 'kitchen' => $company->auto_print_kitchen],
            'agent' => [
                'installed' => $company->print_agent_token !== null,
                'online' => $company->print_agent_seen_at?->gt(now()->subSeconds(self::AGENT_ONLINE_SECONDS)) ?? false,
                'seen_at' => $company->print_agent_seen_at?->format('d/m/Y H:i'),
            ],
            'discovered' => [
                'printers' => $company->print_agent_printers ?? [],
                'searching' => $company->printerSearchPending(),
                'found_at' => $company->print_agent_printers_at?->format('d/m/Y H:i'),
            ],
            'jobs' => PrintJob::with('printer:id,name')->latest('id')->limit(20)->get()->map(fn (PrintJob $job) => [
                'id' => $job->id,
                'title' => $job->title,
                'printer' => $job->printer->name,
                'status' => $job->status,
                'error' => $job->error,
                'attempts' => $job->attempts,
                'created_at' => $job->created_at->format('d/m H:i:s'),
            ]),
        ]);
    }

    public function store(PrinterRequest $request): RedirectResponse
    {
        Printer::create($this->data($request));

        return back()->with('success', 'Impressora cadastrada.');
    }

    public function update(PrinterRequest $request, Printer $printer): RedirectResponse
    {
        $printer->update($this->data($request));

        return back()->with('success', 'Impressora salva.');
    }

    public function destroy(Printer $printer): RedirectResponse
    {
        $printer->delete();

        return back()->with('success', 'Impressora excluída.');
    }

    public function test(Printer $printer, PrintQueue $queue): RedirectResponse
    {
        $queue->test($printer);

        return back()->with('success', 'Teste enviado. Com o agente ligado, a folha sai em instantes.');
    }

    /** Para onde vai cada papel: a comanda de cada setor e o pedido completo. */
    public function destinations(Request $request): RedirectResponse
    {
        $printerRule = ['nullable', 'integer', Rule::exists('printers', 'id')->where('company_id', $request->user()->company_id)];
        $data = $request->validate([
            'order_printer_id' => $printerRule,
            'bill_printer_id' => $printerRule,
            'sectors' => ['array'],
            'sectors.*' => $printerRule,
            'auto_print_orders' => ['sometimes', 'boolean'],
            'auto_print_kitchen' => ['sometimes', 'boolean'],
        ]);

        Company::findOrFail($request->user()->company_id)->forceFill([
            'order_printer_id' => $data['order_printer_id'] ?? null,
            'bill_printer_id' => $data['bill_printer_id'] ?? null,
            ...array_intersect_key($data, array_flip(['auto_print_orders', 'auto_print_kitchen'])),
        ])->save();

        foreach ($data['sectors'] ?? [] as $sectorId => $printerId) {
            ProductionSector::whereKey($sectorId)->first()?->forceFill(['printer_id' => $printerId])->save();
        }

        return back()->with('success', 'Destinos de impressão salvos.');
    }

    public function retry(PrintJob $job): RedirectResponse
    {
        $job->forceFill(['status' => PrintJob::PENDING, 'attempts' => 0, 'error' => null, 'claimed_at' => null])->save();

        return back()->with('success', 'Papel enviado de novo para a fila.');
    }

    /**
     * Baixa o agente já com o endereço do sistema e uma chave nova.
     * A chave anterior deixa de valer: só o último agente baixado imprime.
     */
    /** Pede ao agente a lista de impressoras do Windows; ela chega na próxima consulta dele (segundos). */
    public function discover(Request $request): RedirectResponse
    {
        Company::findOrFail($request->user()->company_id)->forceFill(['print_agent_printers_requested_at' => now()])->saveQuietly();

        return back();
    }

    public function agent(Request $request): HttpResponse
    {
        $token = Str::random(48);
        Company::findOrFail($request->user()->company_id)->forceFill(['print_agent_token' => hash('sha256', $token), 'print_agent_seen_at' => null])->saveQuietly();

        $script = strtr(file_get_contents(resource_path('print-agent/agente-impressao.ps1')), [
            '__SERVER__' => rtrim(url('/'), '/'),
            '__TOKEN__' => $token,
        ]);

        return response($script, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="agente-impressao.ps1"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function data(PrinterRequest $request): array
    {
        $data = $request->validated();
        $data['address'] = trim($data['address']);
        $data['port'] = $data['connection'] === Printer::NETWORK ? $data['port'] : null;

        return $data;
    }
}
