<?php

namespace App\Http\Controllers;

use App\Actions\ConnectWhatsApp;
use App\Actions\DisconnectWhatsApp;
use App\Actions\SaveWhatsAppSettings;
use App\Http\Requests\WhatsAppSettingsRequest;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use App\Support\EvolutionException;
use App\Support\WhatsAppBot\BotReplies;
use App\Support\WhatsAppTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppController extends Controller
{
    public function edit(Request $request, EvolutionClient $evolution): Response
    {
        $companyId = $request->user()->company_id;
        $setting = WhatsAppSetting::where('company_id', $companyId)->first();
        $hasInstance = $setting !== null && filled($setting->instance_name) && filled($setting->instance_token);

        if ($hasInstance && $evolution->configured()) {
            try {
                $setting->state = $evolution->state($setting->instance_name, $setting->instance_token);
            } catch (EvolutionException) {
                $setting->state = 'close';
            }
            $setting->state_checked_at = now();
            $setting->save();
        }

        $connected = $setting?->isConnected() ?? false;
        $overrides = $setting?->templates ?? [];

        return Inertia::render('online/WhatsApp', [
            'available' => $evolution->configured(),
            'settings' => [
                'enabled' => (bool) $setting?->enabled,
                'connected' => $connected,
                'state' => $setting?->state,
                'has_instance' => $hasInstance,
                'templates' => collect(WhatsAppTemplates::EVENTS)->map(fn (string $label, string $key) => [
                    'key' => $key,
                    'label' => $label,
                    'default' => WhatsAppTemplates::DEFAULTS[$key],
                    'value' => $overrides[$key] ?? null,
                ])->values(),
            ],
            'bot' => [
                'auto_reply' => (bool) $setting?->auto_reply,
                'greeting' => $setting?->greeting,
                'default_greeting' => BotReplies::DEFAULT_INTRO,
                'ignored_numbers' => $setting?->ignored_numbers ?? [],
                'conversations' => WhatsAppConversation::where('company_id', $companyId)
                    ->latest('updated_at')
                    ->latest('id')
                    ->limit(30)
                    ->get()
                    ->map(fn (WhatsAppConversation $conversation) => [
                        'id' => $conversation->id,
                        'phone' => $conversation->phone,
                        'name' => $conversation->contact_name,
                        'status_label' => $conversation->statusLabel(),
                        'updated_at' => $conversation->updated_at?->toIso8601String(),
                    ])->values(),
            ],
            'qr' => $connected ? null : Cache::get("whatsapp-qr:{$companyId}"),
            'messages' => WhatsAppMessage::with('onlineOrder:id,number')
                ->where('company_id', $companyId)
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (WhatsAppMessage $message) => [
                    'id' => $message->id,
                    'number' => $message->onlineOrder?->number,
                    'event_label' => WhatsAppTemplates::EVENTS[$message->event] ?? $message->event,
                    'status' => $message->status,
                    'attempts' => $message->attempts,
                    'last_error' => $message->last_error,
                    'created_at' => $message->created_at?->toIso8601String(),
                    'sent_at' => $message->sent_at?->toIso8601String(),
                ])->values(),
        ]);
    }

    public function connect(Request $request, ConnectWhatsApp $action): RedirectResponse
    {
        $action->handle($request->user()->company);

        return back()->with('success', 'Leia o QR code com o celular da loja.');
    }

    public function disconnect(Request $request, DisconnectWhatsApp $action): RedirectResponse
    {
        $action->handle($request->user()->company);

        return back()->with('success', 'WhatsApp desconectado.');
    }

    public function update(WhatsAppSettingsRequest $request, SaveWhatsAppSettings $action): RedirectResponse
    {
        $action->handle($request->user()->company, $request->validated());

        return back()->with('success', 'Configurações do WhatsApp salvas.');
    }
}
