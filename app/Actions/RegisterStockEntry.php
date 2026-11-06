<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockEntry;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RegisterStockEntry
{
    public function __construct(private MoveStock $moveStock) {}

    /**
     * @param  array{supplier_id: ?int, invoice_number: ?string, entry_date: string, notes: ?string}  $data
     * @param  array<int, array{product_id: int, quantity: string, unit_cost: string}>  $items
     */
    public function handle(array $data, array $items): StockEntry
    {
        // Sempre na mesma ordem: duas entradas simultâneas não travam uma à outra.
        $items = collect($items)->sortBy('product_id')->values();

        return DB::transaction(function () use ($data, $items) {
            $user = Auth::user();

            $entry = new StockEntry($data);
            $entry->user_id = $user?->getKey();
            $entry->user_name = $user?->name ?? 'Sistema';
            $entry->status = StockEntry::CONFIRMED;
            $entry->total = $items->reduce(
                fn (string $sum, array $item) => bcadd($sum, $this->lineTotal($item), 4),
                '0',
            );
            $entry->save();

            $products = Product::findMany($items->pluck('product_id'))->keyBy('id');

            foreach ($items as $item) {
                $entry->items()->create([...$item, 'total' => $this->lineTotal($item)]);

                $this->moveStock->handle(
                    $products[$item['product_id']],
                    StockMovement::ENTRY,
                    (string) $item['quantity'],
                    (string) $item['unit_cost'],
                    entry: $entry,
                );
            }

            return $entry;
        });
    }

    private function lineTotal(array $item): string
    {
        return bcmul((string) $item['quantity'], (string) $item['unit_cost'], 4);
    }
}
