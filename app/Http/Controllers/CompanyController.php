<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompanyProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Dados da própria empresa. O CNPJ só o super administrador altera. */
class CompanyController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('company/Edit', [
            'company' => $request->user()->company->only(
                'legal_name', 'trade_name', 'document', 'segment', 'email', 'phone',
                'zip_code', 'street', 'number', 'complement', 'district', 'city', 'state',
            ),
            'segments' => config('company.segments'),
            'states' => config('company.states'),
        ]);
    }

    public function update(CompanyProfileRequest $request): RedirectResponse
    {
        $request->user()->company->update($request->validated());

        return back()->with('success', 'Dados da empresa atualizados.');
    }
}
