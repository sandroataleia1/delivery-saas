<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AppVersionTest extends TestCase
{
    use RefreshDatabase;

    private string $manifest;

    private ?string $originalManifest = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifest = public_path('build/manifest.json');
        $this->originalManifest = File::exists($this->manifest) ? File::get($this->manifest) : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalManifest === null) {
            File::delete($this->manifest);
        } else {
            File::put($this->manifest, $this->originalManifest);
        }

        parent::tearDown();
    }

    public function test_version_matches_the_one_the_open_screens_were_loaded_with(): void
    {
        File::ensureDirectoryExists(dirname($this->manifest));
        File::put($this->manifest, '{"versao":"a"}');
        $loaded = app(HandleInertiaRequests::class)->version(request());

        $this->get(route('app.version'))->assertOk()->assertExactJson(['version' => $loaded])->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_version_changes_after_a_new_build(): void
    {
        File::ensureDirectoryExists(dirname($this->manifest));
        File::put($this->manifest, '{"versao":"a"}');
        $before = $this->getJson(route('app.version'))->json('version');

        File::put($this->manifest, '{"versao":"b"}');

        $this->assertNotSame($before, $this->getJson(route('app.version'))->json('version'));
    }
}
