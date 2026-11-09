<?php

namespace App\Support\WhatsAppBot;

use App\Support\StoreSchedule;
use Carbon\CarbonImmutable;

/**
 * Todos os textos fixos do bot, num lugar só.
 */
final class BotReplies
{
    public const DEFAULT_INTRO = "Olá! 👋 Aqui é {loja}.\nVeja o cardápio e peça pelo link: {link}";

    public const NOT_UNDERSTOOD = 'Não entendi. Digite o número da opção.';

    public const OFFER_ATTENDANT = 'Se preferir, digite 0 para falar com um atendente.';

    public const MEDIA = 'Por aqui eu só entendo texto. Digite o número da opção.';

    public const ATTENDANT = 'Certo, um atendente vai falar com você por aqui.';

    /**
     * Saudação: abertura (editável pela loja, com {loja} e {link}) seguida das opções, que o sistema sempre monta.
     */
    public static function greeting(string $storeName, string $link, ?string $override, bool $open, ?CarbonImmutable $nextOpening, bool $canOrder): string
    {
        $intro = strtr(filled($override) ? trim($override) : self::DEFAULT_INTRO, ['{loja}' => $storeName, '{link}' => $link]);
        $options = [];

        if (! $open) {
            $options[] = $nextOpening ? 'Estamos fechados agora. Abrimos '.StoreSchedule::openingText($nextOpening, CarbonImmutable::now()).'.' : 'Estamos fechados agora.';
        } elseif ($canOrder) {
            $options[] = 'Se preferir pedir por aqui, digite 1.';
        }

        $options[] = 'Para falar com um atendente, digite 0.';

        return $intro."\n\n".implode("\n", $options);
    }

    public static function notUnderstood(int $inARow): string
    {
        return $inARow >= 2 ? self::NOT_UNDERSTOOD."\n".self::OFFER_ATTENDANT : self::NOT_UNDERSTOOD;
    }
}
