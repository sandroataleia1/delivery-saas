<?php

namespace App\Http\Controllers;

use App\Actions\PauseOnlineStore;
use App\Actions\SaveOnlineStore;
use App\Actions\SetOnlineAutoAccept;
use App\Http\Requests\OnlineStoreRequest;
use App\Models\OnlineStore;
use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnlineStoreController extends Controller
{
    public function edit(Request $request): Response
    {
        $store = OnlineStore::with('hours')->where('company_id', $request->user()->company_id)->first();

        return Inertia::render('online/Settings', [
            'store' => $store ? [
                ...$store->only('enabled', 'slug', 'accepts_delivery', 'accepts_pickup', 'min_order', 'eta_text', 'whatsapp', 'accept_minutes'),
                'logo_url' => $store->logoUrl(),
                'hours' => $store->hours->map(fn ($hour) => [
                    'weekday' => (int) $hour->weekday,
                    'opens_at' => substr($hour->opens_at, 0, 5),
                    'closes_at' => substr($hour->closes_at, 0, 5),
                ])->values(),
                'paused' => $store->isPaused(),
                'paused_by_name' => $store->paused_by_name,
                'paused_reason' => $store->paused_reason,
                'label' => StoreSchedule::label($store, CarbonImmutable::now()),
            ] : null,
        ]);
    }

    public function update(OnlineStoreRequest $request, SaveOnlineStore $save): RedirectResponse
    {
        $save->handle($request->validated());

        return back()->with('success', 'Loja online salva.');
    }

    public function pause(Request $request, PauseOnlineStore $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $action->pause($data['reason'] ?? null);

        return back()->with('success', 'Loja pausada.');
    }

    public function autoAccept(Request $request, SetOnlineAutoAccept $action): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $action->handle($data['enabled']);

        return back()->with('success', $data['enabled'] ? 'Aceite automático ligado.' : 'Aceite automático desligado.');
    }

    public function resume(PauseOnlineStore $action): RedirectResponse
    {
        $action->resume();

        return back()->with('success', 'Loja reaberta.');
    }
}
