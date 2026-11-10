<?php

namespace App\Support;

/** Seleção de tamanho, sabores e opções enviada por um canal público (QR Code ou loja online). */
class ItemSelection
{
    /**
     * Seleção na ordem escolhida pelo cliente, usada só para compor o rótulo.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function raw(array $line): array
    {
        $selection = self::normalized($line);
        if (! empty($line['flavor_ids'])) {
            $selection['flavor_ids'] = array_map('intval', $line['flavor_ids']);
        }

        return $selection;
    }

    /**
     * Seleção normalizada (ids ordenados) para o content_hash. Linhas sem composição não ganham chaves.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    public static function normalized(array $line): array
    {
        $selection = [];
        if (! empty($line['size_id'])) {
            $selection['size_id'] = (int) $line['size_id'];
        }
        if (! empty($line['flavor_ids'])) {
            $flavorIds = array_map('intval', $line['flavor_ids']);
            sort($flavorIds);
            $selection['flavor_ids'] = $flavorIds;
        }
        if (! empty($line['options'])) {
            $options = array_map(fn ($option) => ['option_id' => (int) $option['option_id'], 'quantity' => (int) ($option['quantity'] ?? 1)], $line['options']);
            usort($options, fn ($a, $b) => [$a['option_id'], $a['quantity']] <=> [$b['option_id'], $b['quantity']]);
            $selection['options'] = $options;
        }

        return $selection;
    }
}
