<?php

namespace App\Support;

use App\Models\User;

/**
 * Novidades da última atualização (config/changelog.php), só com os itens
 * que a pessoa consegue usar. A tela mostra uma vez e guarda que já viu.
 */
class WhatsNew
{
    /** @return array{id: string, date: string, items: list<array{title: string, text: string}>}|null */
    public static function for(?User $user): ?array
    {
        $release = config('changelog.0');
        if ($user === null || ! is_array($release)) {
            return null;
        }

        $items = collect($release['items'] ?? [])
            ->filter(fn (array $item) => ! isset($item['can']) || $user->can($item['can']))
            ->map(fn (array $item) => ['title' => $item['title'], 'text' => $item['text']])
            ->values()
            ->all();

        return $items === [] ? null : ['id' => $release['id'], 'date' => $release['date'], 'items' => $items];
    }
}
