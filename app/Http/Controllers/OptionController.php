<?php

namespace App\Http\Controllers;

use App\Actions\PauseOption;
use App\Actions\SaveOptionGroup;
use App\Http\Requests\OptionGroupRequest;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\OptionRecipeItem;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OptionController extends Controller
{
    public function index(): Response
    {
        $groups = OptionGroup::query()
            ->where('kind', OptionGroup::EXTRA)
            ->with(['options.recipeItems' => fn ($query) => $query->whereNull('pizza_size_id'), 'options.recipeItems.ingredient:id,name,unit,cost_price,stock_quantity', 'products:id'])
            ->orderBy('name')
            ->get()
            ->map(fn (OptionGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'min_choices' => $group->min_choices,
                'max_choices' => $group->max_choices,
                'allows_quantity' => $group->allows_quantity,
                'active' => $group->active,
                'product_ids' => $group->products->sortBy('pivot.position')->pluck('id')->values(),
                'options' => $group->options->map(fn (Option $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'price' => $option->price,
                    'active' => $option->active,
                    'paused' => $option->paused,
                    'recipe' => $option->recipeItems
                        ->sortBy('ingredient.name')
                        ->values()
                        ->map(fn (OptionRecipeItem $item) => $item->toEditorRow()),
                ])->values(),
            ]);

        return Inertia::render('products/Options', [
            'groups' => $groups,
            'products' => Product::where('active', true)->where('sellable', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(OptionGroupRequest $request, SaveOptionGroup $action): RedirectResponse
    {
        $action->handle(null, $request->validated());

        return redirect()->route('options.index')->with('success', 'Grupo de opções criado.');
    }

    public function update(OptionGroupRequest $request, OptionGroup $group, SaveOptionGroup $action): RedirectResponse
    {
        abort_unless($group->kind === OptionGroup::EXTRA, 404);

        $action->handle($group, $request->validated());

        return redirect()->route('options.index')->with('success', 'Grupo de opções atualizado.');
    }

    public function pause(Request $request, Option $option, PauseOption $action): RedirectResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);
        $action->handle($option, (bool) $data['paused']);

        return back()->with('success', $option->paused ? 'Opção pausada.' : 'Opção retomada.');
    }
}
