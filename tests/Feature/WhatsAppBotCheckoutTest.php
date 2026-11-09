<?php

namespace Tests\Feature;

use App\Actions\PlaceOnlineOrder;
use App\Models\DeliveryZone;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\WhatsAppBot\BotReplies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\WhatsAppBotScenario;
use Tests\TestCase;

class WhatsAppBotCheckoutTest extends TestCase
{
    use RefreshDatabase, WhatsAppBotScenario;

    private const PHONE = '11988887777';

    private Product $coke;

    private Product $juice;

    private DeliveryZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
        $drinks = $this->category('Bebidas');
        $this->juice = $this->product('Suco', '5.00', $drinks);
        $this->coke = $this->product('Coca', '10.00', $drinks);
        $this->zone = new DeliveryZone(['name' => 'Centro', 'fee' => '6.00']);
        $this->zone->company_id = $this->company->id;
        $this->zone->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Pede {quantidade} Coca e para no carrinho. */
    private function cokeInCart(string $quantity = '2'): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1', $quantity, 'pular']);
    }

    /** Do carrinho até o resumo: entrega no Centro, nome do WhatsApp, Pix. */
    private function deliveryCheckout(): void
    {
        $this->customerAnswers(['3', '1', '1', 'Rua das Flores', '45', 'pular', 'Perto da praça', '1', '2']);
    }

    private function previousOrder(): void
    {
        app(PlaceOnlineOrder::class)->handle($this->store, [
            'client_uuid' => (string) Str::uuid(),
            'type' => 'delivery',
            'customer' => ['name' => 'Cliente Teste', 'phone' => self::PHONE],
            'address' => ['street' => 'Rua Antiga', 'number' => '10', 'complement' => 'Casa 2', 'reference' => null, 'delivery_zone_id' => $this->zone->id],
            'items' => [['product_id' => $this->coke->id, 'quantity' => 1]],
            'payment_method' => 'pix',
        ]);
    }

    public function test_delivery_order_is_created_as_online_order_with_catalog_totals(): void
    {
        $this->cokeInCart();
        $this->customerSays('3');
        $this->assertSame("Como prefere receber?\n1 – entrega\n2 – retirar na loja\n\n9 – voltar · 0 – atendente", $this->lastSent());
        $this->customerSays('1');
        $this->assertSame("Qual o seu bairro?\n1 – Centro – taxa R$ 6,00\n\n9 – voltar · 0 – atendente", $this->lastSent());
        $this->customerAnswers(['1', 'Rua das Flores', '45', 'pular', 'Perto da praça']);
        $this->assertSame("Em nome de quem é o pedido?\n1 – usar Cliente Teste\nOu escreva outro nome.", $this->lastSent());
        $this->customerAnswers(['1', '2']);

        $this->assertSame(
            "Confira seu pedido:\n1. 2x Coca – R$ 20,00\nSubtotal: R$ 20,00\nEntrega (Centro): R$ 6,00\nTotal: R$ 26,00\n\n"
            ."Entrega em: Rua das Flores, 45 (Centro) – Perto da praça\nNome: Cliente Teste\nPagamento: Pix\n\n1 – confirmar\n2 – alterar\n0 – atendente",
            $this->lastSent(),
        );

        $this->customerSays('1');

        $order = OnlineOrder::sole();
        $this->assertSame(OnlineOrder::PENDING, $order->status);
        $this->assertSame(self::PHONE, $order->customer_phone);
        $this->assertSame('Cliente Teste', $order->customer_name);
        $this->assertSame($this->zone->id, $order->delivery_zone_id);
        $this->assertSame(['20.00', '6.00', '26.00'], [$order->subtotal, $order->delivery_fee, $order->total]);
        $this->assertSame('Rua das Flores', $order->street);
        $this->assertSame('pix', $order->payment_method);
        $this->assertSame(1, WhatsAppMessage::where('online_order_id', $order->id)->where('event', 'received')->count());
        $this->assertSame("Pedido #{$order->number} enviado! Você vai receber as atualizações por aqui.", $this->lastSent());
        $this->assertSame($order->id, $this->conversation()->last_online_order_id);
        $this->assertSame([], $this->conversation()->context['cart'] ?? []);
    }

    public function test_automatic_order_notification_echo_does_not_pause_bot(): void
    {
        $this->cokeInCart();
        $this->deliveryCheckout();
        $this->customerSays('1');

        $this->storeSays(WhatsAppMessage::sole()->body);

        $this->assertNull($this->conversation()->human_until);
    }

    public function test_pickup_skips_address_and_reuse_offers_last_address(): void
    {
        $this->cokeInCart();
        $this->customerAnswers(['3', '2']);
        $this->assertStringStartsWith('Em nome de quem é o pedido?', $this->lastSent());

        $this->previousOrder();
        $this->customerAnswers(['9', '1']);
        $this->assertSame(
            "Entregar no mesmo endereço do último pedido?\nRua Antiga, 10 – Casa 2 (Centro)\n1 – sim\n2 – outro endereço\n\n9 – voltar · 0 – atendente",
            $this->lastSent(),
        );
        $this->customerAnswers(['1', '1', '2', '1']);

        $order = OnlineOrder::latest('id')->first();
        $this->assertSame(['delivery', 'Rua Antiga', '10', 'Casa 2', $this->zone->id], [$order->type, $order->street, $order->number_address, $order->complement, $order->delivery_zone_id]);
    }

    public function test_cash_asks_change_and_rejects_change_below_total(): void
    {
        $this->cokeInCart();
        $this->customerAnswers(['3', '2', '1', '1']);
        $this->assertSame('Precisa de troco? O total é R$ 20,00. Digite para quanto (ex.: 50) ou digite não preciso.', $this->lastSent());

        $this->customerSays('15');
        $this->assertSame('O troco precisa ser maior que o total.', $this->lastSent());
        $this->customerSays('R$ 50,00');
        $this->assertStringContainsString('Pagamento: Dinheiro (troco para R$ 50,00)', $this->lastSent());
        $this->customerSays('1');

        $this->assertSame('50.00', OnlineOrder::sole()->change_for);
    }

    public function test_cash_without_change(): void
    {
        $this->cokeInCart();
        $this->customerAnswers(['3', '2', '1', '1', 'Não preciso', '1']);

        $this->assertNull(OnlineOrder::sole()->change_for);
    }

    public function test_confirming_twice_creates_one_order(): void
    {
        $this->cokeInCart();
        $this->deliveryCheckout();
        $confirm = $this->fixture('customer_text', '1');

        $this->deliver($confirm);
        $this->deliver($confirm);
        $this->customerSays('1');

        $this->assertSame(1, OnlineOrder::count());
    }

    public function test_changed_cart_after_refusal_gets_new_identifier(): void
    {
        $this->store->forceFill(['min_order' => '30.00'])->save();
        $this->cokeInCart('1');
        $this->deliveryCheckout();
        $this->customerSays('1');

        $this->assertSame(0, OnlineOrder::count());
        $this->assertContains('Pedido mínimo de R$ 30,00.', $this->sentTexts());
        $this->assertStringStartsWith('Seu pedido:', $this->lastSent());
        $firstUuid = $this->conversation()->cart_uuid;

        $this->customerAnswers(['1', '1', '1', '2', 'pular']);
        $this->deliveryCheckout();
        $this->customerSays('1');

        $order = OnlineOrder::sole();
        $this->assertSame('36.00', $order->total);
        $this->assertNotSame($firstUuid, $order->client_uuid);
    }

    public function test_product_sold_out_before_confirm_returns_to_cart(): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1', '1', 'pular', '1', '1', '2', '1', 'pular']);
        $this->deliveryCheckout();
        $this->juice->update(['sold_out' => true]);

        $this->customerSays('1');

        $this->assertSame(0, OnlineOrder::count());
        $this->assertContains('O item Suco ficou indisponível. Tirei do carrinho.', $this->sentTexts());
        $this->assertSame("Seu pedido:\n1. 1x Coca – R$ 10,00\nSubtotal: R$ 10,00\n\n1 – adicionar mais itens\n2 – remover um item\n3 – finalizar\n0 – atendente", $this->lastSent());
    }

    public function test_store_closing_mid_conversation_explains_and_creates_nothing(): void
    {
        $this->at('2026-11-07 22:50');
        $this->cokeInCart();
        $this->deliveryCheckout();
        $this->later(15);

        $this->customerSays('1');

        $this->assertSame(0, OnlineOrder::count());
        $this->assertContains('A loja acabou de fechar.', $this->sentTexts());
        $this->assertStringContainsString('Estamos fechados agora.', $this->lastSent());
        $this->assertSame([], $this->conversation()->context['cart'] ?? []);
    }

    public function test_house_number_nine_is_an_answer(): void
    {
        $this->cokeInCart();
        $this->customerAnswers(['3', '1', '1', 'Rua das Flores', '9']);

        $this->assertStringStartsWith('Tem complemento', $this->lastSent());
    }

    public function test_zero_after_sent_order_calls_attendant(): void
    {
        $this->cokeInCart();
        $this->deliveryCheckout();
        $this->customerSays('1');

        $this->customerSays('0');

        $this->assertSame(BotReplies::ATTENDANT, $this->lastSent());
        $this->assertNotNull($this->conversation()->waiting_since);
    }

    public function test_site_order_notification_echo_creates_no_conversation(): void
    {
        $this->previousOrder();

        $this->storeSays(WhatsAppMessage::sole()->body);

        $this->assertSame(0, WhatsAppConversation::count());
    }

    public function test_pending_limit_per_phone_is_explained(): void
    {
        $this->previousOrder();
        $this->previousOrder();
        $this->previousOrder();
        $this->cokeInCart();
        $this->customerAnswers(['3', '2', '1', '2']);

        $this->customerSays('1');

        $this->assertSame(3, OnlineOrder::count());
        $this->assertSame('Você já tem pedidos aguardando a loja. Assim que ela responder, você pode pedir de novo.', $this->lastSent());
        $this->assertSame('done', $this->conversation()->state);
    }
}
