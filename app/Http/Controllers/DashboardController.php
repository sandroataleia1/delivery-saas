<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\TableOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $canSeeAudit = $request->user()->hasPermission('audit.view');

        $salesToday = Sale::query()->where('status', Sale::COMPLETED)->whereDate('created_at', today());

        return Inertia::render('Dashboard', [
            'stats' => [
                'active_users' => User::where('active', true)->count(),
                'roles' => Role::count(),
                'logins_today' => AuditLog::where('event', 'login')->whereDate('created_at', today())->count(),
                'events_today' => AuditLog::whereDate('created_at', today())->count(),
                'sales_total' => $request->user()->hasPermission('sales.view') ? (clone $salesToday)->sum('total') : null,
                'sales_count' => $request->user()->hasPermission('sales.view') ? (clone $salesToday)->count() : null,
                'overdue_bills' => $request->user()->hasPermission('finance.view') ? Bill::query()->where('type', Bill::PAYABLE)->overdue()->count() : null,
                'open_tables' => $request->user()->hasPermission('tables.operate') ? TableOrder::where('status', TableOrder::OPEN)->count() : null,
                'low_stock' => $request->user()->hasPermission('stock.view') ? Product::query()->lowStock()->count() : null,
            ],
            'recent' => $canSeeAudit
                ? AuditLog::latest('id')->limit(8)->get()->map->present()
                : [],
        ]);
    }
}
