<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WhatsNewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['changelog' => [
            ['id' => '2026-11-10.1', 'date' => '10/11/2026', 'items' => [
                ['title' => 'Painel da cozinha', 'text' => 'Tela cheia por setor.', 'can' => 'production.view'],
                ['title' => 'Relatório por entregador', 'text' => 'Entregas por período.', 'can' => 'reports.view'],
                ['title' => 'Tela mais rápida', 'text' => 'Para todos.'],
            ]],
            ['id' => '2026-11-01.1', 'date' => '01/11/2026', 'items' => [
                ['title' => 'Antiga', 'text' => 'Não aparece mais.'],
            ]],
        ]]);
    }

    public function test_logged_user_receives_only_the_latest_release_with_the_items_they_can_use(): void
    {
        $cook = $this->userWith(['production.view'], $this->adminOf()->company);

        $this->actingAs($cook)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('whatsNew.id', '2026-11-10.1')
                ->where('whatsNew.date', '10/11/2026')
                ->has('whatsNew.items', 2)
                ->where('whatsNew.items.0.title', 'Painel da cozinha')
                ->where('whatsNew.items.1.title', 'Tela mais rápida')
                ->missing('whatsNew.items.0.can'));
    }

    public function test_nothing_to_show_when_no_item_of_the_release_is_for_the_user(): void
    {
        config(['changelog.0.items' => [['title' => 'Relatório', 'text' => 'Só gestão.', 'can' => 'reports.view']]]);

        $this->actingAs($this->userWith(['production.view'], $this->adminOf()->company))->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('whatsNew', null));
    }

    public function test_public_store_does_not_receive_the_release_notes(): void
    {
        $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('whatsNew', null));
    }
}
