<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Cliente mínimo da Evolution API. Toda falha de rede ou resposta não 2xx vira EvolutionException.
 */
class EvolutionClient
{
    public function configured(): bool
    {
        return filled(config('services.evolution.url')) && filled(config('services.evolution.key'));
    }

    /**
     * @return array{token: string, qr: ?string}
     */
    public function createInstance(string $name): array
    {
        $data = $this->send('POST', '/instance/create', (string) config('services.evolution.key'), [
            'instanceName' => $name,
            'qrcode' => true,
            'integration' => 'WHATSAPP-BAILEYS',
        ])->json() ?? [];

        $hash = $data['hash'] ?? null;
        $token = is_array($hash) ? ($hash['apikey'] ?? null) : $hash;

        if (! is_string($token) || $token === '') {
            throw new EvolutionException('A Evolution não devolveu o token da instância.');
        }

        $qr = data_get($data, 'qrcode.base64');

        return ['token' => $token, 'qr' => is_string($qr) ? $qr : null];
    }

    public function connect(string $name, string $token): ?string
    {
        $base64 = $this->send('GET', '/instance/connect/'.rawurlencode($name), $token)->json('base64');

        return is_string($base64) ? $base64 : null;
    }

    public function state(string $name, string $token): string
    {
        $state = $this->send('GET', '/instance/connectionState/'.rawurlencode($name), $token)->json('instance.state');

        return is_string($state) && $state !== '' ? $state : 'close';
    }

    public function logout(string $name, string $token): void
    {
        $this->send('DELETE', '/instance/logout/'.rawurlencode($name), $token);
    }

    public function sendText(string $name, string $token, string $number, string $text): void
    {
        $this->send('POST', '/message/sendText/'.rawurlencode($name), $token, [
            'number' => $number,
            'text' => $text,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $method, string $path, string $apiKey, array $body = []): Response
    {
        if (! $this->configured()) {
            throw new EvolutionException('A Evolution API não está configurada.');
        }

        try {
            $response = $this->request($apiKey)->send($method, $path, $method === 'GET' || $method === 'DELETE' ? [] : ['json' => $body]);
        } catch (ConnectionException $e) {
            throw new EvolutionException(
                Str::limit('Falha de conexão com a Evolution: '.$e->getMessage(), 255, ''),
                0,
                $e,
                retriable: $this->neverReachedEvolution($e),
            );
        }

        if (! $response->successful()) {
            $detail = Str::limit(trim(preg_replace('/\s+/', ' ', $response->body()) ?? ''), 120, '');

            throw new EvolutionException(
                Str::limit('A Evolution respondeu '.$response->status().($detail !== '' ? ': '.$detail : '.'), 255, ''),
                status: $response->status(),
            );
        }

        return $response;
    }

    /** Só é seguro reenviar quando a requisição certamente não chegou à Evolution. */
    private function neverReachedEvolution(ConnectionException $e): bool
    {
        return preg_match('/cURL error (6|7)\b|Connection timed out|Resolving timed out|Failed to connect|Could not resolve host/i', $e->getMessage()) === 1;
    }

    private function request(string $apiKey): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
            ->withHeaders(['apikey' => $apiKey])
            ->acceptJson()
            ->timeout((int) config('services.evolution.timeout'))
            ->connectTimeout(5);
    }
}
