<?php

namespace App\Http\Middleware;

use App\Models\OnlineOrder;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn () => ! $request->routeIs('menu.*') && $request->user() ? [
                'user' => [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role?->name,
                    'is_super_admin' => $request->user()->is_super_admin,
                    'must_change_password' => $request->user()->must_change_password,
                ],
                'company' => $request->user()->company?->only('id', 'trade_name'),
                'permissions' => $request->user()->permissions(),
            ] : null,
            'onlinePendingCount' => fn () => $request->user()?->company_id && $request->user()->can('online_orders.accept') && ! $request->routeIs('menu.*')
                ? OnlineOrder::where('company_id', $request->user()->company_id)->where('status', OnlineOrder::PENDING)->where('expires_at', '>', now())->count()
                : null,
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
        ];
    }
}
