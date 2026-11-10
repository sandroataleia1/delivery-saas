<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\PrintJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** O que o agente de impressão da loja conversa com o servidor: buscar papéis e avisar como foi. */
class PrintAgentController extends Controller
{
    public function jobs(Request $request): JsonResponse
    {
        $company = $this->company($request);

        $jobs = DB::transaction(function () use ($company) {
            $jobs = PrintJob::withoutGlobalScopes()->with('printer')
                ->where('company_id', $company->id)
                ->where('status', PrintJob::PENDING)
                ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(PrintJob::CLAIM_SECONDS)))
                ->orderBy('id')->limit(10)->lockForUpdate()->get();

            PrintJob::withoutGlobalScopes()->whereKey($jobs->modelKeys())->update(['claimed_at' => now()]);

            return $jobs;
        });

        return response()->json([
            'jobs' => $jobs->map(fn (PrintJob $job) => [
                'id' => $job->id,
                'title' => $job->title,
                'payload' => $job->payload,
                'printer' => $job->printer->target(),
            ])->values(),
            'refresh_printers' => $company->printerSearchPending(),
        ]);
    }

    /** O agente conta quais impressoras o Windows tem (instaladas e compartilhadas). */
    public function printers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printers' => ['present', 'array', 'max:100'],
            'printers.*.name' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F"]/'],
            'printers.*.share' => ['nullable', 'string', 'max:255', 'not_regex:/[\x00-\x1F"]/'],
            'printers.*.default' => ['nullable', 'boolean'],
        ]);

        $this->company($request)->forceFill([
            'print_agent_printers' => array_map(fn (array $printer) => [
                'name' => $printer['name'],
                'share' => $printer['share'] ?? null,
                'default' => (bool) ($printer['default'] ?? false),
            ], $data['printers']),
            'print_agent_printers_at' => now(),
        ])->saveQuietly();

        return response()->json(['ok' => true]);
    }

    public function printed(Request $request, int $job): JsonResponse
    {
        $printJob = $this->job($request, $job);
        $printJob->forceFill(['status' => PrintJob::PRINTED, 'printed_at' => now(), 'error' => null])->save();

        return response()->json(['ok' => true]);
    }

    public function failed(Request $request, int $job): JsonResponse
    {
        $data = $request->validate(['error' => ['nullable', 'string', 'max:1000']]);
        $printJob = $this->job($request, $job);
        $attempts = $printJob->attempts + 1;

        $printJob->forceFill([
            'attempts' => $attempts,
            'error' => mb_substr($data['error'] ?? 'Falha sem detalhe.', 0, 255),
            'claimed_at' => null,
            'status' => $attempts >= PrintJob::MAX_ATTEMPTS ? PrintJob::FAILED : PrintJob::PENDING,
        ])->save();

        return response()->json(['ok' => true]);
    }

    private function company(Request $request): Company
    {
        return $request->attributes->get('company');
    }

    private function job(Request $request, int $job): PrintJob
    {
        return PrintJob::withoutGlobalScopes()->where('company_id', $this->company($request)->id)
            ->where('status', PrintJob::PENDING)->findOrFail($job);
    }
}
