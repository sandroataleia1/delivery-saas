<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ProductionSector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SectorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->adminOf();
        $this->actingAs($this->admin);
    }

    private function sector(string $name): ProductionSector
    {
        return ProductionSector::factory()->create(['company_id' => $this->admin->company_id, 'name' => $name]);
    }

    private function category(string $name, ?ProductionSector $sector = null): Category
    {
        return Category::factory()->create(['company_id' => $this->admin->company_id, 'name' => $name, 'production_sector_id' => $sector?->id]);
    }

    public function test_pagina_lista_setores_com_suas_categorias(): void
    {
        $bar = $this->sector('Bar');
        $this->category('Bebidas', $bar);
        $this->category('Lanches');

        $this->get('/setores')->assertOk()->assertInertia(fn (Assert $page) => $page->component('production/Sectors')
            ->where('sectors.0.name', 'Bar')
            ->where('sectors.0.categories.0.name', 'Bebidas')
            ->has('categories', 2));
    }

    public function test_novo_setor_recebe_categorias_inclusive_tirando_de_outro_setor(): void
    {
        $kitchen = $this->sector('Cozinha');
        $drinks = $this->category('Bebidas', $kitchen);
        $desserts = $this->category('Sobremesas');

        $this->post('/setores', ['name' => 'Bar', 'active' => true, 'category_ids' => [$drinks->id, $desserts->id]])->assertSessionHasNoErrors();

        $bar = ProductionSector::where('name', 'Bar')->sole();
        $this->assertSame($bar->id, $drinks->fresh()->production_sector_id);
        $this->assertSame($bar->id, $desserts->fresh()->production_sector_id);
    }

    public function test_categoria_desmarcada_volta_para_o_setor_padrao(): void
    {
        $bar = $this->sector('Bar');
        $drinks = $this->category('Bebidas', $bar);
        $juices = $this->category('Sucos', $bar);

        $this->put("/setores/{$bar->id}", ['name' => 'Bar', 'active' => true, 'category_ids' => [$juices->id]])->assertSessionHasNoErrors();

        $this->assertNull($drinks->fresh()->production_sector_id);
        $this->assertSame($bar->id, $juices->fresh()->production_sector_id);
    }

    public function test_editar_sem_enviar_categorias_mantem_as_atuais(): void
    {
        $bar = $this->sector('Bar');
        $drinks = $this->category('Bebidas', $bar);

        $this->put("/setores/{$bar->id}", ['name' => 'Bar e balcão', 'active' => true])->assertSessionHasNoErrors();

        $this->assertSame($bar->id, $drinks->fresh()->production_sector_id);
    }

    public function test_setor_inativo_nao_pode_ficar_com_categorias(): void
    {
        $bar = $this->sector('Bar');
        $drinks = $this->category('Bebidas', $bar);

        $this->put("/setores/{$bar->id}", ['name' => 'Bar', 'active' => false])->assertSessionHasErrors('active');
        $this->put("/setores/{$bar->id}", ['name' => 'Bar', 'active' => false, 'category_ids' => [$drinks->id]])->assertSessionHasErrors('active');
        $this->assertTrue($bar->fresh()->active);

        $this->put("/setores/{$bar->id}", ['name' => 'Bar', 'active' => false, 'category_ids' => []])->assertSessionHasNoErrors();
        $this->assertFalse($bar->fresh()->active);
        $this->assertNull($drinks->fresh()->production_sector_id);
    }

    public function test_categoria_de_outra_empresa_e_recusada(): void
    {
        $foreign = Category::factory()->create(['company_id' => $this->adminOf()->company_id]);

        $this->post('/setores', ['name' => 'Bar', 'active' => true, 'category_ids' => [$foreign->id]])->assertSessionHasErrors('category_ids.0');

        $this->assertNull($foreign->fresh()->production_sector_id);
        $this->assertSame(0, ProductionSector::where('name', 'Bar')->count());
    }

    public function test_endereco_antigo_leva_para_setores(): void
    {
        $this->get('/producao/setores')->assertRedirect('/setores');
    }

    public function test_so_quem_configura_acessa(): void
    {
        $this->actingAs($this->userWith(['production.view'], $this->admin->company));

        $this->get('/setores')->assertForbidden();
        $this->post('/setores', ['name' => 'Bar', 'active' => true])->assertForbidden();
    }
}
