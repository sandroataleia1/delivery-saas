<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppInbound;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppInboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\WhatsAppBotScenario;
use Tests\TestCase;

class WhatsAppBotRoutingTest extends TestCase
{
    use RefreshDatabase, WhatsAppBotScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_first_message_gets_greeting_with_store_link_once(): void
    {
        $this->customerSays('Oi');
        $this->customerSays('tudo bem?');

        $sent = $this->sentTexts();
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Pizzaria Bella', $sent[0]);
        $this->assertStringContainsString(route('shop.show', 'bella'), $sent[0]);
        $this->assertStringContainsString('digite 1', $sent[0]);
        $this->assertStringContainsString('digite 0', $sent[0]);
        $this->assertStringStartsWith('Não entendi.', $sent[1]);
        $this->assertSentTo('5511988887777');
        $this->assertSame('Cliente Teste', $this->conversation()->contact_name);
        $this->assertSame('11988887777', $this->conversation()->phone);
    }

    public function test_second_misunderstanding_in_a_row_offers_attendant(): void
    {
        $this->customerSays('Oi');
        $this->customerSays('hein');
        $this->customerSays('???');

        $sent = $this->sentTexts();
        $this->assertStringNotContainsString('atendente', $sent[1]);
        $this->assertStringContainsString('digite 0 para falar com um atendente', $sent[2]);
    }

    public function test_greeting_again_only_after_12_hours_of_silence(): void
    {
        $this->customerSays('Oi');
        $this->later(11 * 60);
        $this->customerSays('Oi');
        $this->later(12 * 60);
        $this->customerSays('Oi');

        $sent = $this->sentTexts();
        $this->assertCount(3, $sent);
        $this->assertStringStartsWith('Não entendi.', $sent[1]);
        $this->assertStringContainsString(route('shop.show', 'bella'), $sent[2]);
    }

    public function test_custom_greeting_replaces_intro_and_keeps_options(): void
    {
        $this->setting->forceFill(['greeting' => 'Bem-vindo à {loja}! Cardápio: {link}'])->save();

        $this->customerSays('Oi');

        $this->assertStringStartsWith('Bem-vindo à Pizzaria Bella! Cardápio: '.route('shop.show', 'bella'), $this->lastSent());
        $this->assertStringContainsString('digite 1', $this->lastSent());
    }

    public function test_closed_store_greeting_has_no_order_option(): void
    {
        $this->at('2026-11-07 15:00');

        $this->customerSays('Oi');

        $this->assertStringNotContainsString('digite 1', $this->lastSent());
        $this->assertStringContainsString('Abrimos hoje às 18h', $this->lastSent());
        $this->assertStringContainsString('digite 0', $this->lastSent());
        $this->customerSays('1');
        $this->assertStringStartsWith('Não entendi.', $this->lastSent());
    }

    public function test_paused_store_greeting_has_no_opening_time(): void
    {
        $this->store->forceFill(['paused_at' => now()])->save();

        $this->customerSays('Oi');

        $this->assertStringContainsString('Estamos fechados agora.', $this->lastSent());
        $this->assertStringNotContainsString('Abrimos', $this->lastSent());
    }

    public function test_store_message_from_phone_pauses_bot_for_two_hours_and_renews(): void
    {
        $this->customerSays('Oi');
        $this->storeSays('Oi, aqui é da loja');
        $this->customerSays('quero saber do meu pedido');
        $this->later(119);
        $this->storeSays('Seu pedido já vai');
        $this->later(61);
        $this->customerSays('obrigado');
        $this->assertCount(1, $this->sentTexts());

        $this->later(60);
        $this->customerSays('hein');

        $this->assertCount(1, $this->sentTexts());
    }

    public function test_greets_again_when_pause_ends_without_store_reply(): void
    {
        $this->customerAnswers(['Oi', '0']);
        $this->later(121);

        $this->customerSays('oi, alguém?');

        $this->assertCount(3, $this->sentTexts());
        $this->assertStringContainsString(route('shop.show', 'bella'), $this->lastSent());
    }

    public function test_store_reply_does_not_overwrite_contact_name(): void
    {
        $this->customerSays('Oi');
        $this->storeSays('Olá!');

        $this->assertSame('Cliente Teste', $this->conversation()->contact_name);
    }

    public function test_bot_echo_is_not_taken_as_attendant(): void
    {
        $this->customerSays('Oi');
        $this->deliver($this->fixture('store_reply_from_phone', $this->lastSent(), ['id' => 'BOT1']));
        $this->deliver($this->fixture('store_reply_from_phone', $this->lastSent(), ['id' => 'ID-QUE-O-BOT-NAO-RECEBEU']));

        $this->assertNull($this->conversation()->human_until);
        $this->customerSays('hein');
        $this->assertCount(2, $this->sentTexts());
    }

    public function test_customer_asking_attendant_pauses_and_marks_waiting(): void
    {
        $this->customerSays('Oi');
        $this->customerSays('0');

        $this->assertStringContainsString('um atendente vai falar com você', $this->lastSent());
        $this->assertNotNull($this->conversation()->waiting_since);
        $this->customerSays('alô?');
        $this->assertCount(2, $this->sentTexts());

        $this->storeSays('Oi, em que posso ajudar?');
        $this->assertNull($this->conversation()->waiting_since);
    }

    public function test_ignored_number_gets_no_reply(): void
    {
        $this->setting->forceFill(['ignored_numbers' => ['11988887777']])->save();

        $this->customerSays('Oi');

        $this->assertSame([], $this->sentTexts());
        $this->assertSame(0, WhatsAppConversation::count());
    }

    public function test_contact_the_store_talked_to_recently_gets_no_greeting(): void
    {
        $this->storeSays('Oi, seu pedido saiu');
        $this->later(3 * 60);

        $this->customerSays('Oi');
        $this->customerSays('chegou');

        $this->assertSame([], $this->sentTexts());
    }

    public function test_auto_reply_off_sends_nothing(): void
    {
        $this->setting->forceFill(['auto_reply' => false])->save();

        $this->customerSays('Oi');

        $this->assertSame([], $this->sentTexts());
    }

    public function test_disabled_store_sends_nothing(): void
    {
        $this->store->forceFill(['enabled' => false])->save();

        $this->customerSays('Oi');

        $this->assertSame([], $this->sentTexts());
    }

    public function test_media_gets_text_only_hint(): void
    {
        $this->customerSays('Oi');
        $this->deliver($this->fixture('customer_audio'));

        $this->assertSame('Por aqui eu só entendo texto. Digite o número da opção.', $this->lastSent());
    }

    public function test_lid_sender_without_phone_gets_link_and_attendant_only(): void
    {
        $this->deliver($this->fixture('customer_lid'));

        $this->assertStringContainsString(route('shop.show', 'bella'), $this->lastSent());
        $this->assertStringNotContainsString('digite 1', $this->lastSent());
        $this->assertSentTo('123456789012345@lid');

        $this->deliver($this->fixture('customer_lid', '1'));
        $this->assertStringStartsWith('Não entendi.', $this->lastSent());
    }

    public function test_evolution_failure_never_fails_the_job(): void
    {
        Http::fake(['evo.test/message/sendText/*' => Http::response('erro', 500)]);

        $this->customerSays('Oi');

        $this->assertNotNull(WhatsAppInboundMessage::value('processed_at'));
        $this->assertSame(1, $this->conversation()->botMessages()->count());
    }

    public function test_conversations_are_isolated_per_company(): void
    {
        [$other, , $otherSetting] = $this->botStore('Lanches do Zé', 'ze', 'inst-z');

        $this->customerSays('Oi');
        $this->customerSays('Oi', $otherSetting);

        $this->assertSame(2, WhatsAppConversation::count());
        $this->assertStringContainsString('Pizzaria Bella', $this->sentTexts('inst-a')[0]);
        $this->assertStringContainsString('Lanches do Zé', $this->sentTexts('inst-z')[0]);
        $this->assertSame('greeted', $this->conversation($other)->state);
    }

    public function test_concurrent_messages_of_same_conversation_are_serialized(): void
    {
        $payload = $this->fixture('customer_text');
        $inbound = WhatsAppInboundMessage::create([
            'company_id' => $this->company->id, 'wa_message_id' => $payload['data']['key']['id'],
            'remote_jid' => $payload['data']['key']['remoteJid'], 'from_me' => false, 'payload' => $payload,
        ]);
        $lock = Cache::lock("whatsapp-conv:{$this->company->id}:123456789012345@lid", 30);
        $lock->get();

        $job = (new ProcessWhatsAppInbound($inbound->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased();
        $this->assertNull($inbound->fresh()->processed_at);
        $this->assertSame(0, WhatsAppConversation::count());
        $lock->release();
    }
}
