<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppInbound;
use App\Models\WhatsAppInboundMessage;
use App\Models\WhatsAppSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Recebe os eventos da Evolution. Só grava a mensagem e despacha o processamento:
 * responde 200 sempre que o segredo confere, para a Evolution nunca reenviar por erro do bot.
 */
class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request, string $instance, string $secret): JsonResponse
    {
        $setting = WhatsAppSetting::withoutGlobalScopes()->where('instance_name', $instance)->first();

        abort_unless($setting !== null && filled($setting->webhook_secret) && hash_equals((string) $setting->webhook_secret, $secret), 404);

        try {
            $this->store($setting, $request->all());
        } catch (Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(WhatsAppSetting $setting, array $payload): void
    {
        if (str_replace('_', '.', strtolower((string) ($payload['event'] ?? ''))) !== 'messages.upsert' || ! is_array($payload['data'] ?? null)) {
            return;
        }

        $id = data_get($payload, 'data.key.id');
        $jid = data_get($payload, 'data.key.remoteJid');

        if (! is_string($id) || $id === '' || strlen($id) > 100 || ! is_string($jid) || $jid === '' || strlen($jid) > 100) {
            return;
        }

        if (str_ends_with($jid, '@g.us') || str_ends_with($jid, '@broadcast') || str_ends_with($jid, '@newsletter')) {
            return;
        }

        $inserted = WhatsAppInboundMessage::insertOrIgnore([
            'company_id' => $setting->company_id,
            'wa_message_id' => $id,
            'remote_jid' => $jid,
            'from_me' => (bool) data_get($payload, 'data.key.fromMe', false),
            'payload' => json_encode($payload),
            'created_at' => now(),
        ]);

        if ($inserted === 1) {
            $messageId = WhatsAppInboundMessage::where('company_id', $setting->company_id)->where('wa_message_id', $id)->value('id');
            ProcessWhatsAppInbound::dispatch($messageId);
        }
    }
}
