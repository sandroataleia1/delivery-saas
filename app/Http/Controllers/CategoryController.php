<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Company;
use App\Models\ProductionSector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('categories/Index', [
            'sectors' => ProductionSector::where('active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => Category::withCount('products')->orderBy('name')->get(['id', 'name', 'production_sector_id']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data) {
            Company::whereKey($request->user()->company_id)->lockForUpdate()->firstOrFail();
            Category::create($data);
        });

        return back()->with('success', 'Categoria criada.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $data = $this->validated($request, $category);
        DB::transaction(function () use ($request, $category, $data) {
            Company::whereKey($request->user()->company_id)->lockForUpdate()->firstOrFail();
            $category->update($data);
        });

        return back()->with('success', 'Categoria atualizada.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->with('error', 'Esta categoria tem produtos. Mova-os antes de excluir.');
        }

        $category->delete();

        return back()->with('success', 'Categoria excluída.');
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'production_sector_id' => ['nullable', 'integer', Rule::exists('production_sectors', 'id')->where('company_id', $request->user()->company_id)->where('active', true)],
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('categories')->where('company_id', $request->user()->company_id)->ignore($category),
            ],
        ], [], ['name' => 'nome']);
    }
}
