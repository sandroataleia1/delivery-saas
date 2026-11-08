<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\OnlineStore;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnlineImagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function productPayload(array $override = []): array
    {
        return [
            'name' => 'Pizza Calabresa',
            'unit' => 'UN',
            'sale_price' => '40.00',
            'track_stock' => false,
            'recipe' => [],
            ...$override,
        ];
    }

    /** @return array<string, mixed> */
    private function storePayload(array $override = []): array
    {
        return [
            'enabled' => false,
            'slug' => 'pizzaria-exemplo',
            'accepts_delivery' => true,
            'accepts_pickup' => true,
            'min_order' => '0',
            'accept_minutes' => 15,
            'hours' => [],
            ...$override,
        ];
    }

    public function test_product_image_upload_and_replace_deletes_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->adminOf();

        $this->actingAs($admin)->post('/produtos', $this->productPayload([
            'image' => UploadedFile::fake()->image('a.jpg', 300, 300),
        ]))->assertRedirect(route('products.index'));

        $product = Product::sole();
        $first = $product->image_path;
        $this->assertStringStartsWith("online/{$admin->company_id}/", $first);
        Storage::disk('public')->assertExists($first);
        $this->assertNotNull($product->imageUrl());

        $this->post("/produtos/{$product->id}", $this->productPayload([
            '_method' => 'put',
            'image' => UploadedFile::fake()->image('b.png', 300, 300),
        ]))->assertRedirect(route('products.index'));

        $second = $product->fresh()->image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->post("/produtos/{$product->id}", $this->productPayload(['_method' => 'put', 'remove_image' => true]))
            ->assertRedirect(route('products.index'));
        $this->assertNull($product->fresh()->image_path);
        Storage::disk('public')->assertMissing($second);
    }

    public function test_rejects_non_image_and_oversized_file(): void
    {
        Storage::fake('public');
        $admin = $this->adminOf();

        $this->actingAs($admin)->postJson('/produtos', $this->productPayload([
            'image' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ]))->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->postJson('/produtos', $this->productPayload([
            'image' => UploadedFile::fake()->image('big.jpg', 300, 300)->size(1500),
        ]))->assertUnprocessable()->assertJsonValidationErrors('image');

        $this->assertSame(0, Product::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_logo_removed_with_flag(): void
    {
        Storage::fake('public');
        $admin = $this->adminOf();

        $this->actingAs($admin)->post('/loja-online/configuracao', $this->storePayload([
            '_method' => 'put',
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]))->assertRedirect();

        $store = OnlineStore::where('company_id', $admin->company_id)->firstOrFail();
        $path = $store->logo_path;
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($store->logoUrl());

        $this->post('/loja-online/configuracao', $this->storePayload(['_method' => 'put', 'remove_logo' => true]))
            ->assertRedirect();

        $this->assertNull($store->fresh()->logo_path);
        $this->assertNull($store->fresh()->logoUrl());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_other_company_cannot_touch_product_image(): void
    {
        Storage::fake('public');
        $admin = $this->adminOf();
        $foreign = Product::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'image_path' => 'online/999/x.jpg',
        ]);
        Storage::disk('public')->put('online/999/x.jpg', 'x');

        $this->actingAs($admin)->post("/produtos/{$foreign->id}", $this->productPayload([
            '_method' => 'put',
            'remove_image' => true,
        ]))->assertNotFound();

        $this->assertSame('online/999/x.jpg', $foreign->fresh()->image_path);
        Storage::disk('public')->assertExists('online/999/x.jpg');
    }
}
