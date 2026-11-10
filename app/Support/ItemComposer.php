<?php

namespace App\Support;

use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\OptionPrice;
use App\Models\OptionRecipeItem;
use App\Models\PizzaSize;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Valida a escolha do cliente (tamanho, sabores e opções) e calcula, só com ids e quantidades,
 * o preço unitário, o snapshot imutável, o rótulo legível e o custo. O espelho em JS precisa
 * reproduzir exatamente o mesmo preço e rótulo.
 */
class ItemComposer
{
    private const FRACTIONS = [2 => '½', 3 => '⅓', 4 => '¼'];

    private const LABEL_LIMIT = 255;

    /** @var array<int, string> custo atual por insumo */
    private array $ingredientCosts = [];

    /**
     * @param  array<int|string, array<string, mixed>>  $items  {product_id, size_id?, flavor_ids?, options?: [{option_id, quantity?}]}
     * @return array<int|string, array{product: Product, unit_price: string, options: array<string, mixed>|null, options_label: string|null, cost: string}>
     *
     * @throws ValidationException
     */
    public function composeMany(int $companyId, array $items): array
    {
        $products = $this->loadProducts($companyId, $items);
        $optionIds = $this->referencedOptionIds($items);
        $options = $this->lockOptions($companyId, $optionIds);
        $prices = $this->lockPrices($companyId, $optionIds);
        $sizes = $this->loadSizes($companyId, $items);
        $recipes = $this->loadRecipes($companyId, $options->keys()->all(), $sizes->keys()->all());
        $groups = [];

        $results = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $product = $products->get((int) ($item['product_id'] ?? 0));

            if ($product === null) {
                $errors["items.$index"] = 'Produto inválido.';

                continue;
            }

            $groups[$product->id] ??= $this->loadGroups($companyId, $product);

            try {
                $results[$index] = $this->composeOne($product, $item, $groups[$product->id], $options, $prices, $sizes, $recipes);
            } catch (CompositionException $exception) {
                $errors["items.$index"] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, OptionGroup>  $groups
     * @param  Collection<int, Option>  $options
     * @param  Collection<string, string>  $prices
     * @param  Collection<int, PizzaSize>  $sizes
     * @param  Collection<int, OptionRecipeItem>  $recipes
     * @return array{product: Product, unit_price: string, options: array<string, mixed>|null, options_label: string|null, cost: string}
     */
    private function composeOne(Product $product, array $item, Collection $groups, Collection $options, Collection $prices, Collection $sizes, Collection $recipes): array
    {
        $sizeId = $item['size_id'] ?? null;
        $flavorIds = array_map('intval', array_values($item['flavor_ids'] ?? []));
        $selections = $this->normalizeSelections($item['options'] ?? []);

        $size = null;
        $flavors = [];

        if ($product->isPizza()) {
            $size = $this->resolveSize($product, $sizeId, $sizes);
            $flavors = $this->resolveFlavors($size, $flavorIds, $groups, $options, $prices);
        } elseif ($sizeId !== null || $flavorIds !== []) {
            throw new CompositionException('Este produto não aceita tamanho nem sabores.');
        }

        $extras = $this->resolveExtras($selections, $groups, $options, $prices, $size);
        $this->assertRequiredGroupsChosen($groups, $extras);

        if ($size === null && $extras === []) {
            return [
                'product' => $product,
                'unit_price' => $product->sale_price,
                'options' => null,
                'options_label' => null,
                'cost' => bcadd($product->effectiveCost(), '0', 4),
            ];
        }

        $flavorValue = $product->isPizza() ? $this->flavorValue($product->pizza_pricing, array_column($flavors, 'price')) : $product->sale_price;
        $extrasValue = array_reduce($extras, fn (string $sum, array $extra) => bcadd($sum, bcmul($extra['price'], (string) $extra['quantity'], 6), 6), '0');
        $fraction = $flavors === [] ? null : bcdiv('1', (string) count($flavors), 4);

        return [
            'product' => $product,
            'unit_price' => Decimal::money(bcadd($flavorValue, $extrasValue, 6)),
            'options' => [
                'size' => $size ? ['id' => $size->id, 'name' => $size->name] : null,
                'pricing' => $product->isPizza() ? $product->pizza_pricing : null,
                'flavors' => array_map(fn (array $flavor) => $flavor + ['fraction' => $fraction], $flavors),
                'extras' => array_map(fn (array $extra) => [
                    'id' => $extra['id'], 'group' => $extra['group'], 'name' => $extra['name'],
                    'quantity' => $extra['quantity'], 'price' => $extra['price'],
                ], $extras),
            ],
            'options_label' => $this->label($size, $flavors, $extras),
            'cost' => $this->cost($product, $size, $flavors, $extras, $recipes),
        ];
    }

    /**
     * @param  array<int, array{option_id: int|string, quantity?: int|string|null}>  $raw
     * @return array<int, int> option_id => quantidade
     */
    private function normalizeSelections(array $raw): array
    {
        $selections = [];

        foreach ($raw as $row) {
            $optionId = (int) $row['option_id'];

            if (isset($selections[$optionId])) {
                throw new CompositionException('Opção repetida.');
            }

            $quantity = (int) ($row['quantity'] ?? 1);

            if ($quantity < 1) {
                throw new CompositionException('Quantidade inválida.');
            }

            $selections[$optionId] = $quantity;
        }

        return $selections;
    }

    /** @param  Collection<int, PizzaSize>  $sizes */
    private function resolveSize(Product $product, mixed $sizeId, Collection $sizes): PizzaSize
    {
        if ($sizeId === null) {
            throw new CompositionException('Escolha o tamanho.');
        }

        $size = $sizes->get((int) $sizeId);

        if ($size === null || (int) $size->product_id !== (int) $product->id || ! $size->active) {
            throw new CompositionException('Tamanho indisponível.');
        }

        return $size;
    }

    /**
     * @param  array<int, int>  $flavorIds
     * @param  Collection<int, OptionGroup>  $groups
     * @param  Collection<int, Option>  $options
     * @param  Collection<string, string>  $prices
     * @return array<int, array{id: int, name: string, price: string}>
     */
    private function resolveFlavors(PizzaSize $size, array $flavorIds, Collection $groups, Collection $options, Collection $prices): array
    {
        if ($flavorIds === []) {
            throw new CompositionException('Escolha pelo menos um sabor.');
        }

        if (count($flavorIds) > $size->max_flavors) {
            throw new CompositionException("O tamanho {$size->name} aceita no máximo {$size->max_flavors} sabor(es).");
        }

        if (count(array_unique($flavorIds)) !== count($flavorIds)) {
            throw new CompositionException('Sabor repetido.');
        }

        $flavorGroup = $groups->first(fn (OptionGroup $group) => $group->kind === OptionGroup::FLAVOR);
        $flavors = [];

        foreach ($flavorIds as $flavorId) {
            $flavor = $options->get($flavorId);

            if ($flavorGroup === null || $flavor === null || (int) $flavor->option_group_id !== (int) $flavorGroup->id) {
                throw new CompositionException('Sabor inválido.');
            }

            $price = $prices->get($flavor->id.':'.$size->id);

            if (! $flavor->active || $flavor->paused || $price === null) {
                throw new CompositionException("O sabor {$flavor->name} está indisponível.");
            }

            $flavors[] = ['id' => $flavor->id, 'name' => $flavor->name, 'price' => $price];
        }

        return $flavors;
    }

    /**
     * Extras na ordem dos grupos do produto e, dentro do grupo, das opções.
     *
     * @param  array<int, int>  $selections
     * @param  Collection<int, OptionGroup>  $groups
     * @param  Collection<int, Option>  $options
     * @param  Collection<string, string>  $prices
     * @return array<int, array{id: int, group_id: int, group: string, single: bool, name: string, quantity: int, price: string}>
     */
    private function resolveExtras(array $selections, Collection $groups, Collection $options, Collection $prices, ?PizzaSize $size): array
    {
        $extras = [];
        $totals = [];
        $groupOrder = $groups->keys()->flip();

        foreach ($selections as $optionId => $quantity) {
            $option = $options->get($optionId);
            $group = $option === null ? null : $groups->get((int) $option->option_group_id);

            if ($group === null || $group->kind !== OptionGroup::EXTRA) {
                throw new CompositionException('Opção inválida para este produto.');
            }

            if (! $group->active || ! $option->active || $option->paused) {
                throw new CompositionException("A opção {$option->name} está indisponível.");
            }

            if ($quantity > 1 && ! $group->allows_quantity) {
                throw new CompositionException("{$group->name} não aceita quantidade maior que 1.");
            }

            $totals[$group->id] = ($totals[$group->id] ?? 0) + $quantity;

            $extras[] = [
                'id' => $option->id,
                'group_id' => $group->id,
                'group' => $group->name,
                'single' => $group->max_choices === 1,
                'name' => $option->name,
                'quantity' => $quantity,
                'price' => ($size ? $prices->get($option->id.':'.$size->id) : null) ?? $option->price,
                'order' => [$groupOrder->get($group->id), $option->position, $option->id],
            ];
        }

        foreach ($totals as $groupId => $total) {
            $group = $groups->get($groupId);

            if ($total > $group->max_choices) {
                throw new CompositionException("Escolha no máximo {$group->max_choices} em {$group->name}.");
            }

            if ($total < $group->min_choices) {
                throw new CompositionException("Escolha pelo menos {$group->min_choices} em {$group->name}.");
            }
        }

        usort($extras, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_map(fn (array $extra) => array_diff_key($extra, ['order' => true]), $extras);
    }

    /**
     * @param  Collection<int, OptionGroup>  $groups
     * @param  array<int, array{group_id: int}>  $extras
     */
    private function assertRequiredGroupsChosen(Collection $groups, array $extras): void
    {
        $chosen = array_column($extras, 'group_id');

        foreach ($groups as $group) {
            if ($group->kind === OptionGroup::EXTRA && $group->active && $group->min_choices >= 1 && ! in_array($group->id, $chosen, true)) {
                throw new CompositionException("Escolha {$group->name}.");
            }
        }
    }

    /** @param  array<int, string>  $prices */
    private function flavorValue(string $pricing, array $prices): string
    {
        if ($pricing === 'average') {
            return bcdiv(array_reduce($prices, fn (string $sum, string $price) => bcadd($sum, $price, 6), '0'), (string) count($prices), 6);
        }

        return array_reduce($prices, fn (string $max, string $price) => bccomp($price, $max, 6) > 0 ? $price : $max, '0');
    }

    /**
     * @param  array<int, array{name: string}>  $flavors
     * @param  array<int, array{group: string, single: bool, name: string, quantity: int}>  $extras
     */
    private function label(?PizzaSize $size, array $flavors, array $extras): ?string
    {
        $parts = [];

        if ($size) {
            $parts[] = $size->name;
        }

        if (count($flavors) === 1) {
            $parts[] = $flavors[0]['name'];
        } elseif ($flavors !== []) {
            $mark = self::FRACTIONS[count($flavors)];
            $parts[] = implode(' ', array_map(fn (array $flavor) => "{$mark} {$flavor['name']}", $flavors));
        }

        foreach ($extras as $extra) {
            $parts[] = $extra['single'] ? "{$extra['group']}: {$extra['name']}" : "+{$extra['quantity']} {$extra['name']}";
        }

        $label = implode(' · ', $parts);

        return mb_strlen($label) > self::LABEL_LIMIT ? mb_substr($label, 0, self::LABEL_LIMIT - 1).'…' : $label;
    }

    /**
     * Custo da ficha base do tamanho, mais o custo de cada sabor na fração dele, mais o das opções.
     *
     * @param  array<int, array{id: int}>  $flavors
     * @param  array<int, array{id: int, quantity: int}>  $extras
     * @param  Collection<int, OptionRecipeItem>  $recipes
     */
    private function cost(Product $product, ?PizzaSize $size, array $flavors, array $extras, Collection $recipes): string
    {
        $total = $size ? $this->sumRows($recipes->whereNull('option_id')->where('pizza_size_id', $size->id)) : bcadd($product->effectiveCost(), '0', 4);

        foreach ($flavors as $flavor) {
            $flavorCost = $this->sumRows($recipes->where('option_id', $flavor['id'])->where('pizza_size_id', $size->id));
            $total = bcadd($total, bcdiv($flavorCost, (string) count($flavors), 4), 4);
        }

        foreach ($extras as $extra) {
            $rows = $recipes->where('option_id', $extra['id']);
            $sized = $size ? $rows->where('pizza_size_id', $size->id) : collect();
            $applicable = $sized->isNotEmpty() ? $sized : $rows->whereNull('pizza_size_id');
            $total = bcadd($total, bcmul($this->sumRows($applicable), (string) $extra['quantity'], 4), 4);
        }

        return $total;
    }

    /** @param  Collection<int, OptionRecipeItem>  $rows */
    private function sumRows(Collection $rows): string
    {
        return $rows->reduce(
            fn (string $sum, OptionRecipeItem $row) => bcadd($sum, bcmul($row->quantity, $this->ingredientCosts[$row->ingredient_id] ?? '0', 4), 4),
            '0.0000',
        );
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return Collection<int, Product>
     */
    private function loadProducts(int $companyId, array $items): Collection
    {
        $ids = collect($items)->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

        return Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->with('recipeItems.ingredient')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return array<int, int>
     */
    private function referencedOptionIds(array $items): array
    {
        return collect($items)
            ->flatMap(fn (array $item) => [
                ...($item['flavor_ids'] ?? []),
                ...array_column($item['options'] ?? [], 'option_id'),
            ])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Option>
     */
    private function lockOptions(int $companyId, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Option::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array<int, int>  $optionIds
     * @return Collection<string, string> preço por "option_id:size_id"
     */
    private function lockPrices(int $companyId, array $optionIds): Collection
    {
        if ($optionIds === []) {
            return collect();
        }

        return OptionPrice::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('option_id', $optionIds)
            ->orderBy('option_id')
            ->orderBy('pizza_size_id')
            ->lockForUpdate()
            ->get()
            ->mapWithKeys(fn (OptionPrice $price) => [$price->option_id.':'.$price->pizza_size_id => $price->price]);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $items
     * @return Collection<int, PizzaSize>
     */
    private function loadSizes(int $companyId, array $items): Collection
    {
        $ids = collect($items)->pluck('size_id')->filter()->map(fn ($id) => (int) $id)->unique()->all();

        return PizzaSize::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param  array<int, int>  $optionIds
     * @param  array<int, int>  $sizeIds
     * @return Collection<int, OptionRecipeItem>
     */
    private function loadRecipes(int $companyId, array $optionIds, array $sizeIds): Collection
    {
        if ($optionIds === [] && $sizeIds === []) {
            return collect();
        }

        $rows = OptionRecipeItem::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(fn ($query) => $query->whereIn('option_id', $optionIds)->orWhereIn('pizza_size_id', $sizeIds))
            ->get();

        $this->ingredientCosts = Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $rows->pluck('ingredient_id')->unique()->all())
            ->pluck('cost_price', 'id')
            ->all();

        return $rows;
    }

    /**
     * @return Collection<int, OptionGroup> grupos do produto, na ordem do pivot, seguidos dos da categoria
     */
    private function loadGroups(int $companyId, Product $product): Collection
    {
        $direct = $product->optionGroups()
            ->withoutGlobalScopes()
            ->where('option_groups.company_id', $companyId)
            ->get()
            ->keyBy('id');

        if ($product->category_id === null) {
            return $direct;
        }

        $fromCategory = OptionGroup::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('kind', OptionGroup::EXTRA)
            ->whereIn('id', DB::table('category_option_group')->where('category_id', $product->category_id)->select('option_group_id'))
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        return $direct->union($fromCategory);
    }
}
