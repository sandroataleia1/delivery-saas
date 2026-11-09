<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Option;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\WhatsAppBotScenario;
use Tests\TestCase;

class WhatsAppBotOrderTest extends TestCase
{
    use RefreshDatabase, WhatsAppBotScenario;

    private Product $coke;

    private Product $burger;

    private Product $pizza;

    private int $grande;

    private Option $barbecue;

    private Option $mustard;

    private Option $bacon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpBot();
        $drinks = $this->category('Bebidas');
        $snacks = $this->category('Lanches');
        $this->coke = $this->product('Coca', '10.00', $drinks);
        $this->burger = $this->product('X-Bacon', '28.00', $snacks);
        $sauces = $this->optionGroup($this->burger, 'Molhos', 1, 2);
        $this->barbecue = $this->option($sauces, 'Barbecue', '0');
        $this->option($sauces, 'Maionese', '2.00');
        $this->mustard = $this->option($sauces, 'Mostarda', '0');
        $extras = $this->optionGroup($this->burger, 'Adicionais', 0, 2, true);
        $this->bacon = $this->option($extras, 'Bacon', '5.00');
        $this->option($extras, 'Cheddar', '4.00', paused: true);
        ['pizza' => $this->pizza, 'grande' => $this->grande] = $this->pizza($this->category('Pizzas'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function cart(): array
    {
        return $this->conversation()->context['cart'] ?? [];
    }

    public function test_lists_only_categories_and_products_available_online(): void
    {
        $desserts = $this->category('Sobremesas');
        $this->product('Pudim', '9.00', $desserts, ['sold_out' => true]);
        $drinks = $this->coke->category;
        $this->product('Suco antigo', '7.00', $drinks, ['active' => false]);
        $this->product('Gelo', '1.00', $drinks, ['sellable' => false]);
        $this->product('Coca da outra loja', '1.00', $this->category('Bebidas', Company::factory()->create()));

        $this->customerAnswers(['Oi', '1']);

        $this->assertSame("Escolha uma categoria:\n1 – Bebidas\n2 – Lanches\n3 – Pizzas\n\n9 – voltar · 0 – atendente", $this->lastSent());

        $this->customerSays('1');

        $this->assertSame("Bebidas:\n1 – Coca – R$ 10,00\n\n9 – voltar · 0 – atendente", $this->lastSent());
        $this->customerAnswers(['9', '3']);
        $this->assertSame("Pizzas:\n1 – Pizza – a partir de R$ 39,90\n\n9 – voltar · 0 – atendente", $this->lastSent());
    }

    public function test_simple_product_goes_to_cart_with_catalog_price(): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1']);
        $this->assertSame('Quantas unidades de Coca? Digite de 1 a 20.', $this->lastSent());

        $this->customerAnswers(['21', '2']);
        $this->assertSame('Alguma observação para este item? Escreva aqui ou digite pular.', $this->lastSent());

        $this->customerSays('pular');

        $this->assertSame([['product_id' => $this->coke->id, 'quantity' => 2, 'label' => 'Coca', 'unit_price' => '10.00']], $this->cart());
        $this->assertSame(
            "Seu pedido:\n1. 2x Coca – R$ 20,00\nSubtotal: R$ 20,00\n\n1 – adicionar mais itens\n2 – remover um item\n3 – finalizar\n0 – atendente",
            $this->lastSent(),
        );
        $this->assertContains('Não entendi. Digite o número da opção.', $this->sentTexts());
    }

    public function test_half_and_half_pizza_respects_size_flavor_limit(): void
    {
        $this->customerAnswers(['Oi', '1', '3', '1']);
        $this->assertStringStartsWith("Escolha o tamanho:\n1 – Broto (1 sabor)\n2 – Grande (até 2 sabores)", $this->lastSent());

        $this->customerSays('2');
        $this->assertStringStartsWith("Escolha até 2 sabores, separados por vírgula (ex.: 1,2):\n1 – Calabresa – R$ 49,90\n2 – Quatro queijos – R$ 59,90\n\n", $this->lastSent());

        $this->customerSays('1,2,2');
        $this->assertSame('Esse tamanho aceita no máximo 2 sabores. Escolha de novo.', $this->lastSent());

        $this->customerAnswers(['1, 2', '1', 'sem cebola']);

        $line = $this->cart()[0];
        $this->assertSame($this->grande, $line['size_id']);
        $this->assertSame([$this->pizza->optionGroups()->first()->options()->where('name', 'Calabresa')->value('id'), $this->pizza->optionGroups()->first()->options()->where('name', 'Quatro queijos')->value('id')], $line['flavor_ids']);
        $this->assertSame('59.90', $line['unit_price']);
        $this->assertSame('sem cebola', $line['notes']);
        $this->assertStringContainsString('½ Calabresa', $line['label']);
        $this->assertStringContainsString('½ Quatro queijos', $line['label']);
        $this->assertStringContainsString('Obs.: sem cebola', $this->lastSent());
    }

    public function test_extras_group_enforces_min_and_max(): void
    {
        $this->customerAnswers(['Oi', '1', '2', '1']);
        $this->assertStringStartsWith("Molhos – escolha de 1 a 2, separados por vírgula:\n1 – Barbecue\n2 – Maionese – + R$ 2,00\n3 – Mostarda", $this->lastSent());

        $this->customerSays('pular');
        $this->assertSame('Escolha pelo menos 1 em Molhos.', $this->lastSent());
        $this->customerSays('1,2,3');
        $this->assertSame('Escolha no máximo 2 em Molhos.', $this->lastSent());

        $this->customerSays('1,3');
        $this->assertStringStartsWith('Adicionais – escolha até 2, separados por vírgula, ou digite pular (repita o número para mais de uma unidade):', $this->lastSent());

        $this->customerAnswers(['1,1', '1', 'pular']);

        $this->assertSame([['option_id' => $this->barbecue->id, 'quantity' => 1], ['option_id' => $this->mustard->id, 'quantity' => 1], ['option_id' => $this->bacon->id, 'quantity' => 2]], $this->cart()[0]['options']);
        $this->assertSame('38.00', $this->cart()[0]['unit_price']);
    }

    public function test_paused_option_is_not_offered(): void
    {
        $this->customerAnswers(['Oi', '1', '2', '1', '1']);

        $this->assertStringNotContainsString('Cheddar', $this->lastSent());
        $this->customerAnswers(['2']);
        $this->assertStringStartsWith('Não entendi.', $this->lastSent());

        $this->customerAnswers(['cancelar', '1', '3', '1', '2']);
        $this->assertStringStartsWith('Escolha até 2 sabores', $this->lastSent());
        $this->assertStringNotContainsString('Portuguesa', $this->lastSent());
    }

    public function test_nine_goes_back_one_step_and_cancel_clears_cart(): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1', '9']);
        $this->assertStringStartsWith('Bebidas:', $this->lastSent());

        $this->customerSays('9');
        $this->assertStringStartsWith('Escolha uma categoria:', $this->lastSent());
        $this->customerSays('9');
        $this->assertStringContainsString('digite 1', $this->lastSent());

        $this->customerAnswers(['1', '1', '1', '1', 'pular', 'cancelar']);

        $this->assertSame([], $this->cart());
        $this->assertStringContainsString('digite 1', $this->lastSent());
    }

    public function test_remove_item_from_cart(): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1', '1', 'pular', '1', '2', '1', '1', 'pular', '1', 'pular']);
        $this->assertCount(2, $this->cart());

        $this->customerSays('2');
        $this->assertSame('Qual item quer remover? Digite o número.', $this->lastSent());
        $this->customerSays('1');

        $this->assertCount(1, $this->cart());
        $this->assertSame($this->burger->id, $this->cart()[0]['product_id']);
        $this->assertStringStartsWith("Item removido.\n\nSeu pedido:\n1. 1x X-Bacon", $this->lastSent());
    }

    public function test_cart_expires_after_two_hours_and_resume_prompt_before_that(): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1', '1', 'pular', '0']);
        $this->later(100);
        $this->customerSays('ainda está aí?');
        $this->later(21);
        $this->customerSays('voltei');

        $this->assertSame("Você tinha um pedido em andamento.\n1 – continuar o pedido\n2 – começar de novo", $this->lastSent());
        $this->customerSays('1');
        $this->assertStringStartsWith("Seu pedido:\n1. 1x Coca", $this->lastSent());

        $this->later(121);
        $this->customerSays('oi');

        $this->assertSame([], $this->cart());
        $this->assertStringContainsString(route('shop.show', 'bella'), $this->lastSent());
    }

    public function test_cart_expires_when_customer_was_silent_through_the_whole_pause(): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1', '1', 'pular', '0']);
        $this->later(121);
        $this->customerSays('voltei');

        $this->assertSame([], $this->cart());
        $this->assertStringContainsString(route('shop.show', 'bella'), $this->lastSent());
    }

    public function test_resume_prompt_can_start_over(): void
    {
        $this->customerAnswers(['Oi', '1', '1', '1', '1', 'pular', '0']);
        $this->later(100);
        $this->customerSays('ainda está aí?');
        $this->later(21);
        $this->customerAnswers(['voltei', '2']);

        $this->assertSame([], $this->cart());
        $this->assertStringStartsWith('Escolha uma categoria:', $this->lastSent());
    }
}
