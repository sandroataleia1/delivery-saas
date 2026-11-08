<?php

namespace Tests\Feature;

use App\Support\EvolutionClient;
use App\Support\EvolutionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvolutionClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.evolution.url' => 'https://evo.test/', 'services.evolution.key' => 'GLOBAL']);
    }

    public function test_create_instance_sends_global_key_and_returns_token_and_qr(): void
    {
        Http::fake(['https://evo.test/instance/create' => Http::response([
            'instance' => ['instanceName' => 'x'],
            'hash' => 'TOKEN1',
            'qrcode' => ['base64' => 'data:image/png;base64,AAA'],
        ])]);

        $result = app(EvolutionClient::class)->createInstance('x');

        $this->assertSame(['token' => 'TOKEN1', 'qr' => 'data:image/png;base64,AAA'], $result);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://evo.test/instance/create'
            && $request->header('apikey') === ['GLOBAL']
            && $request['instanceName'] === 'x'
            && $request['qrcode'] === true
            && $request['integration'] === 'WHATSAPP-BAILEYS');
    }

    public function test_create_instance_accepts_hash_object(): void
    {
        Http::fake(['https://evo.test/instance/create' => Http::response([
            'hash' => ['apikey' => 'TOKEN2'],
        ])]);

        $this->assertSame(['token' => 'TOKEN2', 'qr' => null], app(EvolutionClient::class)->createInstance('x'));
    }

    public function test_send_text_uses_instance_token_and_number_with_country(): void
    {
        Http::fake(['https://evo.test/message/sendText/x' => Http::response(['key' => []], 201)]);

        app(EvolutionClient::class)->sendText('x', 'TOKEN1', '5511988887777', 'Oi');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://evo.test/message/sendText/x'
            && $request->header('apikey') === ['TOKEN1']
            && $request->data() === ['number' => '5511988887777', 'text' => 'Oi']);
    }

    public function test_state_reads_instance_state_and_defaults_to_close(): void
    {
        Http::fake(['https://evo.test/instance/connectionState/x' => Http::sequence()
            ->push(['instance' => ['instanceName' => 'x', 'state' => 'open']])
            ->push([])]);

        $client = app(EvolutionClient::class);

        $this->assertSame('open', $client->state('x', 'TOKEN1'));
        $this->assertSame('close', $client->state('x', 'TOKEN1'));
    }

    public function test_failures_become_evolution_exception(): void
    {
        Http::fake(['https://evo.test/message/sendText/x' => Http::response(['error' => 'boom'], 500)]);

        try {
            app(EvolutionClient::class)->sendText('x', 'T', '5511988887777', 'Oi');
            $this->fail('Deveria lançar EvolutionException.');
        } catch (EvolutionException $e) {
            $this->assertLessThanOrEqual(255, mb_strlen($e->getMessage()));
        }

        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(EvolutionException::class);
        app(EvolutionClient::class)->sendText('x', 'T', '5511988887777', 'Oi');
    }

    public function test_not_configured(): void
    {
        config(['services.evolution.url' => '']);

        $this->assertFalse(app(EvolutionClient::class)->configured());
    }
}
