<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['ativos', 'inativos'])],
        ]);

        return Inertia::render('suppliers/Index', [
            'suppliers' => Supplier::query()
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                    fn ($query) => $query->where('name', 'like', "%$search%")->orWhere('document', 'like', "%$search%")
                ))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('active', $status === 'ativos'))
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('suppliers/Form');
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        Supplier::create($request->validated());

        return redirect()->route('suppliers.index')->with('success', 'Fornecedor criado.');
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('suppliers/Form', [
            'supplier' => $supplier->only('id', 'name', 'document', 'email', 'phone', 'notes'),
        ]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->validated());

        return redirect()->route('suppliers.index')->with('success', 'Fornecedor atualizado.');
    }

    public function toggleStatus(Supplier $supplier): RedirectResponse
    {
        $supplier->active = ! $supplier->active;
        $supplier->save();

        return back()->with('success', $supplier->active ? 'Fornecedor ativado.' : 'Fornecedor inativado.');
    }
}
