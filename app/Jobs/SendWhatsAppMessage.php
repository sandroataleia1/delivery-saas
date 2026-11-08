<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSetting;
use App\Support\EvolutionClient;
use App\Support\EvolutionException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    /** Atrasos (segundos) antes da próxima tentativa, indexados por tentativas já feitas - 1. */
    public const BACKOFF = [30, 120, 600, 1800];

    public int $tries = 6;

    public function __construct(public int $messageId) {}

    /**
     * Nunca lança: nada do envio pode chegar a quem mudou o estado do pedido.
     */
    public function handle(EvolutionClient $client): void
    {
        try {
            $claimed = WhatsAppMessage::withoutGlobalScopes()
                ->whereKey($this->messageId)
                ->where('status', WhatsAppMessage::PENDING)
                ->where('attempts', '<', WhatsAppMessage::MAX_ATTEMPTS)
                ->update([
                    'status' => WhatsAppMessage::SENDING,
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            report($e);

            return;
        }

        if ($claimed !== 1) {
            return;
        }

        $message = null;
        $settings = null;

        try {
            $message = WhatsAppMessage::withoutGlobalScopes()->findOrFail($this->messageId);
            $settings = WhatsAppSetting::withoutGlobalScopes()->where('company_id', $message->company_id)->first();

            if ($settings === null || ! $settings->enabled || blank($settings->instance_name) || ! $client->configured()) {
                $this->finish($message, WhatsAppMessage::FAILED, 'WhatsApp desligado');

                return;
            }

            $client->sendText($settings->instance_name, (string) $settings->instance_token, $message->phone, $message->body);

            WhatsAppMessage::withoutGlobalScopes()->whereKey($message->id)->update([
                'status' => WhatsAppMessage::SENT,
                'sent_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);
            $settings->forceFill(['state' => 'open', 'state_checked_at' => now()])->saveQuietly();
        } catch (EvolutionException $e) {
            $this->handleFailure($message, $settings, $client, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->handleFailure($message, $settings, $client, 'Erro interno no envio');
        }
    }

    private function finish(WhatsAppMessage $message, string $status, string $error): void
    {
        WhatsAppMessage::withoutGlobalScopes()->whereKey($message->id)->update([
            'status' => $status,
            'last_error' => mb_substr($error, 0, 500),
            'updated_at' => now(),
        ]);
    }

    private function handleFailure(?WhatsAppMessage $message, ?WhatsAppSetting $settings, EvolutionClient $client, string $error): void
    {
        try {
            $attempts = (int) WhatsAppMessage::withoutGlobalScopes()->whereKey($this->messageId)->value('attempts');
            $exhausted = $attempts >= WhatsAppMessage::MAX_ATTEMPTS;

            $this->finish(
                $message ?? new WhatsAppMessage(['id' => $this->messageId]),
                $exhausted ? WhatsAppMessage::FAILED : WhatsAppMessage::PENDING,
                $error,
            );

            if (! $exhausted) {
                $this->release(self::BACKOFF[min(max($attempts, 1), count(self::BACKOFF)) - 1]);
            }
        } catch (Throwable $e) {
            report($e);
        }

        $this->refreshConnectionState($settings, $client);
    }

    private function refreshConnectionState(?WhatsAppSetting $settings, EvolutionClient $client): void
    {
        if ($settings === null || blank($settings->instance_name)) {
            return;
        }

        try {
            $state = $client->state($settings->instance_name, (string) $settings->instance_token);
            $settings->forceFill(['state' => $state, 'state_checked_at' => now()])->saveQuietly();
        } catch (Throwable) {
            // Passo opcional: falhar aqui não muda o resultado do envio.
        }
    }
}
