<?php

namespace App\Http\Controllers\Auth;

use App\Actions\CreateCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Register', [
            'segments' => config('company.segments'),
            'states' => config('company.states'),
        ]);
    }

    public function store(RegisterRequest $request, CreateCompany $createCompany): RedirectResponse
    {
        $data = $request->validated();

        $company = $createCompany->handle(
            [
                ...Arr::except($data, ['owner_name', 'owner_phone', 'password']),
            ],
            [
                'name' => $data['owner_name'],
                'email' => $data['email'],
                'phone' => $data['owner_phone'],
                'password' => $data['password'],
            ],
            mustChangePassword: false,
        );

        $owner = $company->users()->sole();

        Audit::record('signup', $company, "{$owner->name} cadastrou a loja {$company->trade_name}", actor: $owner);

        Auth::login($owner);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Sua loja foi criada. Boas-vindas!');
    }
}
