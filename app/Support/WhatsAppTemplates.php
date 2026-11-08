<?php

namespace App\Support;

/** Textos das mensagens de WhatsApp do pedido online: padrões e personalização por loja. */
class WhatsAppTemplates
{
    /** @var array<string, string> */
    public const EVENTS = [
        'received' => 'Pedido recebido',
        'accepted' => 'Pedido aceito',
        'preparing' => 'Em preparo',
        'ready_for_pickup' => 'Pronto para retirar',
        'out_for_delivery' => 'Saiu para entrega',
        'delivered' => 'Entregue',
        'rejected' => 'Recusado',
        'cancelled' => 'Cancelado',
        'expired' => 'Expirado',
    ];

    /** @var array<string, string> */
    public const DEFAULTS = [
        'received' => "Olá, {nome}! Recebemos seu pedido #{numero} na {loja}, no total de {total}. Avisamos por aqui assim que a loja confirmar.\nAcompanhe: {link}",
        'accepted' => "{nome}, a {loja} aceitou seu pedido #{numero}.\nAcompanhe: {link}",
        'preparing' => "Seu pedido #{numero} está em preparo na {loja}.\nAcompanhe: {link}",
        'ready_for_pickup' => "Seu pedido #{numero} está pronto para retirar na {loja}.\nAcompanhe: {link}",
        'out_for_delivery' => "Seu pedido #{numero} saiu para entrega.\nAcompanhe: {link}",
        'delivered' => "Pedido #{numero} concluído. Obrigado por pedir na {loja}!\n{link}",
        'rejected' => "A {loja} não pôde aceitar seu pedido #{numero}. Motivo: {motivo}\n{link}",
        'cancelled' => "Seu pedido #{numero} na {loja} foi cancelado. Motivo: {motivo}\n{link}",
        'expired' => "A {loja} não confirmou seu pedido #{numero} a tempo, e ele não será preparado.\n{link}",
    ];

    private const MARKERS = ['nome', 'loja', 'numero', 'link', 'motivo', 'total'];

    /**
     * Monta o texto do evento. Marcadores desconhecidos ficam como estão; o link sempre vai junto.
     *
     * @param  array<string, mixed>|null  $overrides
     * @param  array{nome: string, loja: string, numero: string, link: string, motivo: string, total: string}  $values
     */
    public static function render(?array $overrides, string $event, array $values): string
    {
        $override = $overrides[$event] ?? null;
        $text = is_string($override) && trim($override) !== '' ? $override : self::DEFAULTS[$event];

        $replacements = [];
        foreach (self::MARKERS as $marker) {
            $replacements['{'.$marker.'}'] = (string) ($values[$marker] ?? '');
        }
        $body = strtr($text, $replacements);

        return str_contains($text, '{link}') ? $body : $body."\n".$values['link'];
    }
}
