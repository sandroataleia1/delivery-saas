<?php

namespace App\Support;

use App\Models\OptionRecipeItem;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Baixa e custo das fichas de tamanho, sabores e opções de uma linha composta, lidos do snapshot
 * (só ids e quantidades). A ficha usada é a atual no momento da venda.
 */
class OptionStock
{
    /**
     * @param  array<int, array<string, mixed>|null>  $snapshots
     * @return Collection<int, int> ids de insumo de todas as fichas referenciadas, em ordem
     */
    public function ingredientIds(array $snapshots): Collection
    {
        return collect($snapshots)
            ->filter()
            ->flatMap(fn (array $snapshot) => $this->rows($snapshot)->pluck('ingredient_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{ingredient_id: int, quantity: string, source: string}>
     */
    public function consumptions(array $snapshot, string $lineQuantity): array
    {
        return array_map(
            fn (array $line) => ['ingredient_id' => $line['ingredient_id'], 'quantity' => bcadd($line['quantity'], '0.0005', 3), 'source' => $line['source']],
            $this->lines($snapshot, $lineQuantity)['lines'],
        );
    }

    /**
     * Custo de uma unidade composta: ficha do produto (só sem tamanho) mais fichas das opções.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function cost(Product $product, array $snapshot): string
    {
        ['lines' => $lines, 'costs' => $costs] = $this->lines($snapshot, '1');

        $total = empty($snapshot['size']) ? bcadd($product->effectiveCost(), '0', 4) : '0.0000';

        foreach ($lines as $line) {
            $total = bcadd($total, bcmul($line['quantity'], $costs[$line['ingredient_id']], 4), 4);
        }

        return $total;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{lines: array<int, array{ingredient_id: int, quantity: string, source: string}>, costs: array<int, string>}
     */
    private function lines(array $snapshot, string $lineQuantity): array
    {
        $rows = $this->rows($snapshot);
        $sizeId = $snapshot['size']['id'] ?? null;
        $flavors = $snapshot['flavors'] ?? [];
        $totals = [];

        $add = function (Collection $recipe, string $source, string $factor, ?string $divisor = null) use (&$totals, $lineQuantity) {
            foreach ($recipe as $row) {
                $quantity = bcmul(bcmul($row->quantity, $lineQuantity, 6), $factor, 6);
                $quantity = $divisor === null ? $quantity : bcdiv($quantity, $divisor, 6);
                $key = $row->ingredient_id.'|'.$source;
                $totals[$key] = [
                    'ingredient_id' => (int) $row->ingredient_id,
                    'quantity' => bcadd($totals[$key]['quantity'] ?? '0', $quantity, 6),
                    'source' => $source,
                ];
            }
        };

        if ($sizeId !== null) {
            $add($rows->whereNull('option_id')->where('pizza_size_id', $sizeId), "Pizza · tamanho {$snapshot['size']['name']}", '1');
        }

        foreach ($flavors as $flavor) {
            $add($rows->where('option_id', $flavor['id'])->where('pizza_size_id', $sizeId), "Pizza · sabor {$flavor['name']}", '1', (string) count($flavors));
        }

        foreach ($snapshot['extras'] ?? [] as $extra) {
            if (! isset($extra['id'], $extra['quantity'])) {
                continue;
            }

            $own = $rows->where('option_id', $extra['id']);
            $sized = $sizeId === null ? collect() : $own->where('pizza_size_id', $sizeId);
            $add($sized->isNotEmpty() ? $sized : $own->whereNull('pizza_size_id'), "{$extra['group']} · {$extra['name']}", (string) $extra['quantity']);
        }

        $lines = array_values($totals);
        usort($lines, fn (array $a, array $b) => [$a['ingredient_id'], $a['source']] <=> [$b['ingredient_id'], $b['source']]);

        return ['lines' => $lines, 'costs' => $this->costs($rows)];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return Collection<int, OptionRecipeItem>
     */
    private function rows(array $snapshot): Collection
    {
        $sizeId = $snapshot['size']['id'] ?? null;
        $optionIds = array_map('intval', [...array_column($snapshot['flavors'] ?? [], 'id'), ...array_column($snapshot['extras'] ?? [], 'id')]);

        if ($sizeId === null && $optionIds === []) {
            return collect();
        }

        return OptionRecipeItem::query()
            ->where(function ($query) use ($sizeId, $optionIds) {
                $query->whereIn('option_id', $optionIds);

                if ($sizeId !== null) {
                    $query->orWhere(fn ($base) => $base->whereNull('option_id')->where('pizza_size_id', $sizeId));
                }
            })
            ->with('ingredient.recipeItems.ingredient')
            ->orderBy('ingredient_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, OptionRecipeItem>  $rows
     * @return array<int, string>
     */
    private function costs(Collection $rows): array
    {
        return $rows->mapWithKeys(fn (OptionRecipeItem $row) => [$row->ingredient_id => $row->ingredient->effectiveCost()])->all();
    }
}
