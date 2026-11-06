<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CreateCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('admin/companies/Index', [
            'companies' => Company::query()
                ->withCount('users')
                ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                    fn ($query) => $query->where('trade_name', 'like', "%$search%")
                        ->orWhere('legal_name', 'like', "%$search%")
                        ->orWhere('document', 'like', "%$search%")
                ))
                ->orderBy('trade_name')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/companies/Form');
    }

    public function store(CompanyRequest $request, CreateCompany $createCompany): RedirectResponse
    {
        $data = $request->validated();

        $company = $createCompany->handle(
            collect($data)->only('legal_name', 'trade_name', 'document', 'email', 'phone')->all(),
            [
                'name' => $data['admin_name'],
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
            ],
        );

        return redirect()->route('admin.companies.index')->with('success', "Empresa {$company->trade_name} criada.");
    }

    public function edit(Company $company): Response
    {
        return Inertia::render('admin/companies/Form', [
            'company' => $company->only('id', 'legal_name', 'trade_name', 'document', 'email', 'phone'),
        ]);
    }

    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        return redirect()->route('admin.companies.index')->with('success', 'Empresa atualizada.');
    }

    public function toggleStatus(Company $company): RedirectResponse
    {
        $company->active = ! $company->active;
        $company->save();

        return back()->with('success', $company->active
            ? 'Empresa ativada.'
            : 'Empresa inativada. Os usuários dela perderam o acesso.');
    }
}
