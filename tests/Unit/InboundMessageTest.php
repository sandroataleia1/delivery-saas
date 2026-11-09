<?php

namespace Tests\Unit;

use App\Support\WhatsAppBot\InboundMessage;
use PHPUnit\Framework\TestCase;

class InboundMessageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__."/../Fixtures/whatsapp/{$name}.json"), true);
    }

    public function test_customer_text_from_lid_uses_alternate_number_with_mobile_nine(): void
    {
        $message = InboundMessage::fromPayload($this->fixture('customer_text'));

        $this->assertNotNull($message);
        $this->assertSame('A5996F187106ED32EBB1ABF6DFECD348', $message->id);
        $this->assertSame('123456789012345@lid', $message->remoteJid);
        $this->assertFalse($message->fromMe);
        $this->assertSame('11988887777', $message->phone);
        $this->assertSame('Cliente Teste', $message->pushName);
        $this->assertSame('Oi', $message->text);
        $this->assertSame('text', $message->kind);
    }

    public function test_store_reply_is_from_me_without_contact_name(): void
    {
        $message = InboundMessage::fromPayload($this->fixture('store_reply_from_phone'));

        $this->assertTrue($message->fromMe);
        $this->assertSame('123456789012345@lid', $message->remoteJid);
        $this->assertSame('11988887777', $message->phone);
        $this->assertNull($message->pushName);
        $this->assertSame('Oi, aqui é da loja', $message->text);
    }

    public function test_audio_is_media_without_text(): void
    {
        $message = InboundMessage::fromPayload($this->fixture('customer_audio'));

        $this->assertSame('media', $message->kind);
        $this->assertNull($message->text);
    }

    public function test_lid_without_alternate_number_has_no_phone(): void
    {
        $this->assertNull(InboundMessage::fromPayload($this->fixture('customer_lid'))->phone);
    }

    public function test_regular_jid_and_extended_text_are_read(): void
    {
        $payload = $this->fixture('customer_text');
        $payload['data']['key'] = ['id' => 'X1', 'remoteJid' => '5511988887777@s.whatsapp.net', 'fromMe' => false];
        $payload['data']['message'] = ['extendedTextMessage' => ['text' => 'quero pedir']];

        $message = InboundMessage::fromPayload($payload);

        $this->assertSame('11988887777', $message->phone);
        $this->assertSame('quero pedir', $message->text);
    }

    public function test_group_status_and_keyless_payloads_are_ignored(): void
    {
        $group = $this->fixture('customer_text');
        $group['data']['key']['remoteJid'] = '120363000000@g.us';
        $status = $this->fixture('customer_text');
        $status['data']['key']['remoteJid'] = 'status@broadcast';

        $this->assertNull(InboundMessage::fromPayload($group));
        $this->assertNull(InboundMessage::fromPayload($status));
        $this->assertNull(InboundMessage::fromPayload(['event' => 'messages.upsert', 'data' => []]));
    }
}
