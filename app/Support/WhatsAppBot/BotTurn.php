<?php

namespace App\Support\WhatsAppBot;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppSetting;
use Carbon\CarbonImmutable;

/**
 * Tudo o que uma resposta do bot precisa saber sobre a loja e a conversa.
 */
final class BotTurn
{
    public function __construct(
        public readonly WhatsAppSetting $setting,
        public readonly OnlineStore $store,
        public readonly Company $company,
        public readonly WhatsAppConversation $conversation,
        public readonly CarbonImmutable $now,
    ) {}
}
