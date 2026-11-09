<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\WhatsAppBot\BotReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\WhatsAppBotScenario;
use Tests\TestCase;

class WhatsAppBotSettingsTest extends TestCase
{
    use RefreshDatabase, WhatsAppBotScenario;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
        Http::fake(['evo.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']])]);
        $this->admin = $this->adminOf($this->company);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_saves_auto_reply_greeting_and_ignored_numbers_with_audit(): void
    {
        $this->actingAs($this->admin)->putJson('/loja-online/whatsapp', [
            'enabled' => true,
            'auto_reply' => false,
            'greeting' => '  Oi! Aqui é a {loja}: {link}  ',
            'ignored_numbers' => ['(11) 8888-7777', '+55 11 97777-6666'],
        ])->assertRedirect();

        $setting = $this->setting->fresh();
        $this->assertFalse($setting->auto_reply);
        $this->assertSame('Oi! Aqui é a {loja}: {link}', $setting->greeting);
        $this->assertSame(['11988887777', '11977776666'], $setting->ignored_numbers);
        $this->assertTrue(AuditLog::where('auditable_type', 'WhatsAppSetting')->where('auditable_id', $setting->id)->where('event', 'updated')->exists());

        $this->putJson('/loja-online/whatsapp', ['enabled' => true, 'auto_reply' => true, 'greeting' => '', 'ignored_numbers' => []])->assertRedirect();

        $this->assertNull($this->setting->fresh()->greeting);
        $this->assertSame([], $this->setting->fresh()->ignored_numbers);
    }

    public function test_invalid_ignored_number_is_refused(): void
    {
        $this->actingAs($this->admin)->putJson('/loja-online/whatsapp', ['enabled' => true, 'auto_reply' => true, 'ignored_numbers' => ['123']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ignored_numbers.0' => 'Número inválido: 123']);
    }

    public function test_screen_shows_bot_settings_and_only_own_company_conversations(): void
    {
        [, , $otherSetting] = $this->botStore('Outra', 'outra', 'inst-b');
        $this->customerSays('Oi');
        $this->customerSays('Oi', $otherSetting);

        $this->actingAs($this->admin)->get('/loja-online/whatsapp')->assertInertia(fn (Assert $page) => $page
            ->where('bot.auto_reply', true)
            ->where('bot.default_greeting', BotReplies::DEFAULT_INTRO)
            ->has('bot.conversations', 1)
            ->where('bot.conversations.0.phone', '11988887777')
            ->where('bot.conversations.0.name', 'Cliente Teste')
            ->where('bot.conversations.0.status_label', 'No roteiro'));
    }

    public function test_waiting_customer_alert_appears_after_zero_and_clears_on_store_reply(): void
    {
        [, , $otherSetting] = $this->botStore('Outra', 'outra', 'inst-b');
        $this->customerAnswers(['Oi', '0']);
        $this->customerSays('Oi', $otherSetting);
        $this->customerSays('0', $otherSetting);

        $this->actingAs($this->admin)->get('/loja-online/pedidos')->assertInertia(fn (Assert $page) => $page
            ->has('waitingCustomers', 1)
            ->where('waitingCustomers.0.name', 'Cliente Teste')
            ->where('waitingCustomers.0.phone', '11988887777')
            ->where('waitingCustomers.0.wa_link', 'https://wa.me/5511988887777'));
        $this->get('/loja-online/whatsapp')->assertInertia(fn (Assert $page) => $page
            ->where('bot.conversations.0.status_label', 'Aguardando atendente'));

        $this->storeSays('Oi, aqui é a loja!');

        $this->get('/loja-online/pedidos')->assertInertia(fn (Assert $page) => $page->has('waitingCustomers', 0));
        $this->get('/loja-online/whatsapp')->assertInertia(fn (Assert $page) => $page
            ->where('bot.conversations.0.status_label', 'Com atendente'));
    }

    public function test_waiting_alert_clears_when_pause_expires(): void
    {
        $this->customerAnswers(['Oi', '0']);
        $this->later(121);

        $this->actingAs($this->admin)->get('/loja-online/pedidos')->assertInertia(fn (Assert $page) => $page->has('waitingCustomers', 0));
    }

    public function test_conversation_with_sent_order_is_labeled(): void
    {
        $this->customerSays('Oi');
        $this->conversation()->forceFill(['state' => 'done'])->save();

        $this->actingAs($this->admin)->get('/loja-online/whatsapp')->assertInertia(fn (Assert $page) => $page
            ->where('bot.conversations.0.status_label', 'Pedido feito'));
    }
}
