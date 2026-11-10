<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductionSectorRequest;
use App\Models\Category;
use App\Models\Company;
use App\Models\ProductionSector;
use App\Models\ProductionWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProductionSectorController extends Controller
{
    public function index(): Response
    {
        $categories = Category::orderBy('name')->get(['id', 'name', 'production_sector_id']);

        return Inertia::render('production/Sectors', [
            'sectors' => ProductionSector::orderBy('name')->get(['id', 'name', 'active'])->map(fn (ProductionSector $sector) => [
                ...$sector->only('id', 'name', 'active'),
                'categories' => $categories->where('production_sector_id', $sector->id)->map->only('id', 'name')->values(),
            ]),
            'categories' => $categories->map->only('id', 'name', 'production_sector_id'),
            'defaultId' => Company::findOrFail(auth()->user()->company_id)->default_production_sector_id,
        ]);
    }

    public function store(ProductionSectorRequest $request): RedirectResponse
    {
        return $this->save($request, new ProductionSector);
    }

    public function update(ProductionSectorRequest $request, ProductionSector $sector): RedirectResponse
    {
        return $this->save($request, $sector);
    }

    private function save(ProductionSectorRequest $request, ProductionSector $sector): RedirectResponse
    {
        DB::transaction(function () use ($request, $sector) {
            $company = Company::whereKey($request->user()->company_id)->lockForUpdate()->firstOrFail();
            $data = $request->validated();
            if (! $data['active'] && ($request->boolean('is_default') || ProductionWork::where('production_sector_id', $sector->id)->whereNull('delivered_at')->whereNull('cancelled_at')->exists())) {
                throw ValidationException::withMessages(['active' => 'Destino padrão e setor com trabalho ativo precisam permanecer ativos.']);
            }
            $categoryIds = array_key_exists('category_ids', $data) ? array_map('intval', $data['category_ids']) : ($sector->exists ? Category::where('production_sector_id', $sector->id)->pluck('id')->all() : []);
            if (! $data['active'] && $categoryIds !== []) {
                throw ValidationException::withMessages(['active' => 'Tire as categorias deste setor antes de desativá-lo.']);
            }
            $sector->fill($request->safe()->only('name', 'active'))->save();
            if (array_key_exists('category_ids', $data)) {
                $this->syncCategories($sector, $categoryIds);
            }
            if ($request->boolean('is_default')) {
                $company->forceFill(['default_production_sector_id' => $sector->id])->save();
            }
        });

        return back()->with('success', 'Setor salvo.');
    }

    /**
     * As categorias marcadas passam a sair neste setor; as desmarcadas voltam para o setor padrão.
     *
     * @param  list<int>  $categoryIds
     */
    private function syncCategories(ProductionSector $sector, array $categoryIds): void
    {
        $affected = Category::query()
            ->where(fn ($query) => $query->where('production_sector_id', $sector->id)->orWhereIn('id', $categoryIds))
            ->lockForUpdate()
            ->get();

        foreach ($affected as $category) {
            $category->update(['production_sector_id' => in_array($category->id, $categoryIds, true) ? $sector->id : null]);
        }
    }

    public function destroy(ProductionSector $sector): RedirectResponse
    {
        DB::transaction(function () use ($sector) {
            Company::whereKey($sector->company_id)->lockForUpdate()->firstOrFail();
            if (ProductionWork::where('production_sector_id', $sector->id)->exists()
                || Category::where('production_sector_id', $sector->id)->exists()
                || Company::where('default_production_sector_id', $sector->id)->exists()) {
                throw ValidationException::withMessages(['sector' => 'Setor tem vínculo ou histórico e não pode ser excluído.']);
            }
            $sector->delete();
        });

        return back()->with('success', 'Setor excluído.');
    }
}
