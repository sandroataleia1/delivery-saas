<?php

namespace App\Support\WhatsAppBot;

use App\Models\Category;
use App\Models\Product;
use App\Support\ProductComposition;
use Illuminate\Support\Collection;

/**
 * O cardápio que o bot oferece: o mesmo da loja online (produto ativo, vendável e não esgotado),
 * sem sabores e opções pausados ou inativos. Só descreve; preço do item fechado vem do ItemComposer.
 */
class BotCatalog
{
    /** Categoria virtual para produtos sem categoria. */
    public const OTHERS = 0;

    /** @var array<int, array<string, mixed>> */
    private array $compositions;

    /** @param  Collection<int, Product>  $products */
    private function __construct(private Collection $products, private Collection $categoryNames)
    {
        $this->compositions = ProductComposition::forProducts($products);
    }

    public static function for(int $companyId): self
    {
        $products = Product::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('active', true)
            ->where('sellable', true)
            ->where('sold_out', false)
            ->orderBy('name')
            ->get(['id', 'name', 'sale_price', 'category_id', 'pizza_pricing']);

        $categoryNames = Category::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('id', $products->pluck('category_id')->filter()->unique()->all())
            ->orderBy('name')
            ->pluck('name', 'id');

        return new self($products, $categoryNames);
    }

    /** @return list<array{id: int, name: string}> */
    public function categories(): array
    {
        $categories = $this->categoryNames->map(fn (string $name, int $id) => ['id' => $id, 'name' => $name])->values()->all();

        if ($this->products->contains(fn (Product $product) => $product->category_id === null)) {
            $categories[] = ['id' => self::OTHERS, 'name' => 'Outros'];
        }

        return $categories;
    }

    public function categoryName(int $categoryId): ?string
    {
        return $categoryId === self::OTHERS ? 'Outros' : $this->categoryNames->get($categoryId);
    }

    /** @return list<array{id: int, name: string, price: string, from: bool}> */
    public function products(int $categoryId): array
    {
        return $this->products
            ->filter(fn (Product $product) => (int) $product->category_id === $categoryId)
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'price' => $this->compositions[$product->id]['from_price'] ?? $product->sale_price,
                'from' => isset($this->compositions[$product->id]),
            ])
            ->values()
            ->all();
    }

    public function productName(int $productId): ?string
    {
        return $this->products->firstWhere('id', $productId)?->name;
    }

    /**
     * Tamanhos, sabores e grupos que dá para escolher pelo bot, só com o que está disponível.
     * Null para produto simples (vai direto para a quantidade).
     *
     * @return array{pizza: array{sizes: list<array<string, mixed>>, flavors: list<array<string, mixed>>}|null, groups: list<array<string, mixed>>}|null
     */
    public function composition(int $productId): ?array
    {
        $composition = $this->compositions[$productId] ?? null;

        if ($composition === null) {
            return null;
        }

        $available = fn (array $options) => array_values(array_filter($options, fn (array $option) => ! $option['paused']));

        return [
            'pizza' => $composition['pizza'] === null ? null : [
                'sizes' => $composition['pizza']['sizes'],
                'flavors' => $available($composition['pizza']['flavors']),
            ],
            'groups' => array_values(array_filter(
                array_map(fn (array $group) => ['options' => $available($group['options'])] + $group, $composition['groups']),
                fn (array $group) => $group['options'] !== [],
            )),
        ];
    }
}
