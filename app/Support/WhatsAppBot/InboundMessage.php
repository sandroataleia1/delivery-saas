<?php

namespace App\Support\WhatsAppBot;

use App\Support\BrazilPhone;

/**
 * Mensagem recebida da Evolution (evento messages.upsert), já interpretada.
 * Contatos novos chegam como "<id>@lid"; o número real vem em key.remoteJidAlt.
 */
final class InboundMessage
{
    private const MEDIA_TYPES = ['imageMessage', 'audioMessage', 'videoMessage', 'documentMessage', 'documentWithCaptionMessage', 'stickerMessage', 'locationMessage', 'contactMessage', 'contactsArrayMessage', 'ptvMessage'];

    /**
     * @param  'text'|'media'|'other'  $kind
     */
    public function __construct(
        public readonly string $id,
        public readonly string $remoteJid,
        public readonly bool $fromMe,
        public readonly ?string $phone,
        public readonly ?string $pushName,
        public readonly ?string $text,
        public readonly string $kind,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $key = data_get($payload, 'data.key');
        $id = is_array($key) ? ($key['id'] ?? null) : null;
        $jid = is_array($key) ? ($key['remoteJid'] ?? null) : null;

        if (! is_string($id) || $id === '' || ! is_string($jid) || $jid === '') {
            return null;
        }

        if (str_ends_with($jid, '@g.us') || str_ends_with($jid, '@broadcast') || str_ends_with($jid, '@newsletter')) {
            return null;
        }

        $fromMe = (bool) ($key['fromMe'] ?? false);
        $message = data_get($payload, 'data.message');
        $message = is_array($message) ? $message : [];
        $text = $message['conversation'] ?? data_get($message, 'extendedTextMessage.text');
        $text = is_string($text) && trim($text) !== '' ? $text : null;
        $pushName = data_get($payload, 'data.pushName');

        return new self(
            id: $id,
            remoteJid: $jid,
            fromMe: $fromMe,
            phone: self::phone($jid, $key),
            pushName: ! $fromMe && is_string($pushName) && trim($pushName) !== '' ? mb_substr(trim($pushName), 0, 80) : null,
            text: $text,
            kind: $text !== null ? 'text' : (array_intersect(self::MEDIA_TYPES, array_keys($message)) !== [] ? 'media' : 'other'),
        );
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private static function phone(string $jid, array $key): ?string
    {
        $candidates = [$jid, $key['remoteJidAlt'] ?? null, $key['senderPn'] ?? null];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && str_ends_with($candidate, '@s.whatsapp.net')) {
                return self::withMobileNine(BrazilPhone::normalize(strstr($candidate, '@', true)));
            }
        }

        return null;
    }

    /** O WhatsApp ainda identifica muitos celulares sem o 9; o sistema guarda com o 9, como no site. */
    public static function withMobileNine(?string $phone): ?string
    {
        if ($phone !== null && strlen($phone) === 10 && in_array($phone[2], ['6', '7', '8', '9'], true)) {
            return substr($phone, 0, 2).'9'.substr($phone, 2);
        }

        return $phone;
    }
}
