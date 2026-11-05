<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $canSeeAudit = $request->user()->hasPermission('audit.view');

        return Inertia::render('Dashboard', [
            'stats' => [
                'active_users' => User::where('active', true)->count(),
                'roles' => Role::count(),
                'logins_today' => AuditLog::where('event', 'login')->whereDate('created_at', today())->count(),
                'events_today' => AuditLog::whereDate('created_at', today())->count(),
                'low_stock' => $request->user()->hasPermission('stock.view') ? Product::query()->lowStock()->count() : null,
            ],
            'recent' => $canSeeAudit
                ? AuditLog::latest('id')->limit(8)->get()->map->present()
                : [],
        ]);
    }
}
