<?php

namespace App\Support\WhatsAppBot;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\WhatsAppBotMessage;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppSetting;
use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Ponto de entrada do bot: decide entre ignorar, pausar para o atendente, saudar ou seguir o roteiro do pedido.
 * Roda fora de sessão: toda consulta filtra company_id explicitamente.
 */
class WhatsAppBot
{
    public const NEW_CONVERSATION_HOURS = 12;

    public const STORE_CONTACT_HOURS = 12;

    public const PAUSE_MINUTES = 120;

    public const ECHO_MINUTES = 2;

    public const IDLE = 'idle';

    public const GREETED = 'greeted';

    public function __construct(private BotSender $sender) {}

    public function handle(WhatsAppSetting $setting, InboundMessage $message): void
    {
        if ($message->fromMe) {
            $this->storeSpoke($setting, $message);

            return;
        }

        if ($message->phone !== null && in_array($message->phone, $setting->ignored_numbers ?? [], true)) {
            return;
        }

        $conversation = $this->conversation($setting->company_id, $message);
        $isNew = $conversation->last_customer_at === null || $conversation->last_customer_at->lte(now()->subHours(self::NEW_CONVERSATION_HOURS));
        $conversation->forceFill([
            'last_customer_at' => now(),
            'phone' => $message->phone ?? $conversation->phone,
            'contact_name' => $message->pushName ?? $conversation->contact_name,
        ])->save();

        $shop = $this->shop($setting->company_id);

        if (! $setting->auto_reply || $shop === null || $conversation->human_until?->isFuture()) {
            return;
        }

        $turn = new BotTurn($setting, $shop['store'], $shop['company'], $conversation, CarbonImmutable::now());

        if ($isNew) {
            if ($conversation->last_store_at?->gt(now()->subHours(self::STORE_CONTACT_HOURS))) {
                return;
            }

            $this->reset($conversation);
            $this->reply($turn, [$this->greeting($turn)]);

            return;
        }

        if ($conversation->state === self::IDLE) {
            return;
        }

        if ($message->kind !== 'text') {
            $this->reply($turn, [BotReplies::MEDIA]);

            return;
        }

        $this->reply($turn, $this->answer($turn, self::normalize((string) $message->text)));
    }

    /** Texto do cliente comparável: sem acentos, minúsculo e sem espaços sobrando. */
    public static function normalize(string $text): string
    {
        return Str::squish(Str::lower(Str::ascii($text)));
    }

    /**
     * @return list<string>
     */
    private function answer(BotTurn $turn, string $text): array
    {
        $conversation = $turn->conversation;

        if ($text === '0') {
            $conversation->forceFill([
                'human_until' => now()->addMinutes(self::PAUSE_MINUTES),
                'waiting_since' => now(),
                'misunderstood' => 0,
            ])->save();

            return [BotReplies::ATTENDANT];
        }

        if ($text === 'cancelar') {
            $this->reset($conversation);

            return [$this->greeting($turn)];
        }

        return $this->misunderstood($conversation);
    }

    /**
     * @return list<string>
     */
    private function misunderstood(WhatsAppConversation $conversation): array
    {
        $conversation->forceFill(['misunderstood' => $conversation->misunderstood + 1])->save();

        return [BotReplies::notUnderstood($conversation->misunderstood)];
    }

    private function greeting(BotTurn $turn): string
    {
        $turn->conversation->forceFill(['state' => self::GREETED])->save();
        $open = StoreSchedule::isOpen($turn->store, $turn->now);

        return BotReplies::greeting(
            $turn->company->trade_name,
            route('shop.show', $turn->store->slug),
            $turn->setting->greeting,
            $open,
            $open || $turn->store->isPaused() ? null : StoreSchedule::nextOpening($turn->store, $turn->now),
            $turn->conversation->phone !== null,
        );
    }

    /**
     * @param  list<string>  $texts
     */
    private function reply(BotTurn $turn, array $texts): void
    {
        foreach ($texts as $text) {
            $this->sender->send($turn->setting, $turn->conversation, $text);
        }
    }

    /** Mensagem enviada pelo celular da loja (não pelo bot): um atendente assumiu, o bot se cala por 2 h. */
    private function storeSpoke(WhatsAppSetting $setting, InboundMessage $message): void
    {
        $companyId = $setting->company_id;

        if (WhatsAppBotMessage::where('company_id', $companyId)->where('wa_message_id', $message->id)->exists()) {
            return;
        }

        $conversation = $this->conversation($companyId, $message);

        if ($message->text !== null && WhatsAppBotMessage::where('conversation_id', $conversation->id)
            ->where('body', $message->text)
            ->where('created_at', '>=', now()->subMinutes(self::ECHO_MINUTES))
            ->exists()) {
            return;
        }

        $conversation->forceFill([
            'last_store_at' => now(),
            'human_until' => now()->addMinutes(self::PAUSE_MINUTES),
            'waiting_since' => null,
        ])->save();
    }

    private function conversation(int $companyId, InboundMessage $message): WhatsAppConversation
    {
        return WhatsAppConversation::firstOrCreate(
            ['company_id' => $companyId, 'remote_jid' => $message->remoteJid],
            ['phone' => $message->phone, 'state' => self::IDLE],
        );
    }

    private function reset(WhatsAppConversation $conversation): void
    {
        $conversation->forceFill([
            'state' => self::IDLE,
            'context' => null,
            'misunderstood' => 0,
            'cart_touched_at' => null,
        ])->save();
    }

    /**
     * @return array{store: OnlineStore, company: Company}|null
     */
    private function shop(int $companyId): ?array
    {
        $store = OnlineStore::withoutGlobalScopes()->where('company_id', $companyId)->where('enabled', true)->whereNotNull('slug')->with('hours')->first();
        $company = $store ? Company::whereKey($companyId)->where('active', true)->first() : null;

        return $company ? ['store' => $store, 'company' => $company] : null;
    }
}
