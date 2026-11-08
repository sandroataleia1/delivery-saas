<?php

namespace App\Http\Controllers;

use App\Actions\SaveOnlineStore;
use App\Http\Requests\OnlineStoreRequest;
use App\Models\OnlineStore;
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
                'hours' => $store->hours->map(fn ($hour) => [
                    'weekday' => (int) $hour->weekday,
                    'opens_at' => substr($hour->opens_at, 0, 5),
                    'closes_at' => substr($hour->closes_at, 0, 5),
                ])->values(),
            ] : null,
        ]);
    }

    public function update(OnlineStoreRequest $request, SaveOnlineStore $save): RedirectResponse
    {
        $save->handle($request->validated());

        return back()->with('success', 'Loja online salva.');
    }
}
