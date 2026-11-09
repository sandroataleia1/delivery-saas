<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Reports\DashboardReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Cada bloco aparece só para quem tem a permissão da área correspondente. */
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $report = new DashboardReport(CarbonImmutable::now());

        $liveAllowed = [
            'online' => $user->hasPermission('online_orders.accept'),
            'deliveries' => $user->hasPermission('deliveries.operate'),
            'tables' => $user->hasPermission('tables.operate'),
        ];

        return Inertia::render('Dashboard', [
            'live' => fn () => in_array(true, $liveAllowed, true) ? $report->live($liveAllowed) : null,
            'sales' => fn () => $user->hasPermission('sales.view') ? $report->sales() : null,
            'analytics' => fn () => $user->hasPermission('reports.view') ? $report->analytics() : null,
            'finance' => fn () => $user->hasPermission('finance.view') ? $report->finance() : null,
            'lowStock' => fn () => $user->hasPermission('stock.view') ? $report->lowStock() : null,
            'recent' => fn () => $user->hasPermission('audit.view') ? AuditLog::latest('id')->limit(6)->get()->map->present() : [],
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }
}
