<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/** Tela de auditoria: a empresa vê a sua; o super administrador vê a plataforma. */
class AuditController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'user' => ['nullable', 'integer'],
            'event' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['user'] ?? null, fn ($query, $user) => $query->where('user_id', $user))
            ->when($filters['event'] ?? null, fn ($query, $event) => $query->where('event', $event))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where('description', 'like', "%$search%"))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $isPlatform = $request->user()->is_super_admin;
        $companies = $isPlatform
            ? Company::whereIn('id', $logs->pluck('company_id')->filter())->pluck('trade_name', 'id')
            : collect();

        return Inertia::render('audit/Index', [
            'logs' => $logs->through(fn (AuditLog $log) => [
                ...$log->present(),
                'company' => $companies[$log->company_id] ?? null,
            ]),
            'filters' => $filters,
            'users' => AuditLog::query()->whereNotNull('user_id')->select('user_id', 'user_name')->distinct()->orderBy('user_name')->get(),
            'events' => __('audit.events'),
            'isPlatform' => $isPlatform,
        ]);
    }
}
