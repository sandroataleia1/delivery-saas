<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

/** Cria ou atualiza um grupo de adicionais com opções, fichas e produtos ligados. */
class SaveOptionGroup
{
    public function __construct(private SyncOptionRecipe $syncRecipe) {}

    /**
     * @param  array{name: string, min_choices: int, max_choices: int, allows_quantity: bool, active: bool, product_ids: array<int, int>, options: array<int, array{id?: int|null, name: string, price: string, active: bool, recipe: array<int, array{ingredient_id: int, quantity: string}>}>}  $data
     */
    public function handle(?OptionGroup $group, array $data): OptionGroup
    {
        return DB::transaction(function () use ($group, $data) {
            $companyId = $group?->company_id ?? auth()->user()->company_id;
            Company::whereKey($companyId)->lockForUpdate()->firstOrFail();

            $group ??= new OptionGroup(['kind' => OptionGroup::EXTRA]);
            $group->fill([
                'name' => $data['name'],
                'min_choices' => $data['min_choices'],
                'max_choices' => $data['max_choices'],
                'allows_quantity' => $data['allows_quantity'],
                'active' => $data['active'],
            ]);
            $group->company_id = $companyId;
            $group->save();

            $group->products()->sync(
                collect($data['product_ids'])->values()->mapWithKeys(
                    fn (int $productId, int $position) => [$productId => ['company_id' => $companyId, 'position' => $position]]
                )->all()
            );

            $keptIds = [];
            $changes = [];

            foreach (array_values($data['options']) as $position => $row) {
                $option = isset($row['id']) ? $group->options()->findOrFail($row['id']) : new Option;
                $option->fill([
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'active' => $row['active'],
                    'position' => $position,
                ]);
                $option->company_id = $companyId;
                $option->option_group_id = $group->id;
                $option->save();
                $keptIds[] = $option->id;

                [$before, $after] = $this->syncRecipe->handle($option, null, $row['recipe'] ?? []);

                if ($before !== $after) {
                    $changes[$option->name] = [$before, $after];
                }
            }

            // Opções omitidas ficam inativas: o histórico de pedidos continua apontando para elas.
            $group->options()->whereNotIn('id', $keptIds)->where('active', true)->get()->each(
                fn (Option $option) => $option->update(['active' => false])
            );

            if ($changes !== []) {
                Audit::record(
                    'updated',
                    $group,
                    old: ['recipes' => array_map(fn ($pair) => $pair[0], $changes)],
                    new: ['recipes' => array_map(fn ($pair) => $pair[1], $changes)],
                );
            }

            return $group;
        });
    }
}
