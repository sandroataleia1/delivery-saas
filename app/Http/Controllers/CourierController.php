<?php

namespace App\Http\Controllers;

use App\Models\Courier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Cadastro de entregadores: quem leva as entregas, com ou sem login no sistema. */
class CourierController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('deliveries/Couriers', [
            'couriers' => Courier::with('user:id,name')->orderByDesc('active')->orderBy('name')->get()->map(fn (Courier $courier) => [
                'id' => $courier->id,
                'name' => $courier->name,
                'phone' => $courier->phone,
                'active' => $courier->active,
                'user_id' => $courier->user_id,
                'user' => $courier->user?->name,
            ]),
            'users' => User::activeCouriers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Courier::create($this->validated($request));

        return back()->with('success', 'Entregador cadastrado.');
    }

    public function update(Request $request, Courier $courier): RedirectResponse
    {
        $courier->update($this->validated($request, $courier));

        return back()->with('success', 'Entregador salvo.');
    }

    /** @return array{name: string, phone: ?string, active: bool, user_id: ?int} */
    private function validated(Request $request, ?Courier $courier = null): array
    {
        $companyId = $request->user()->company_id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:20'],
            'active' => ['boolean'],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('company_id', $companyId)->where('active', true),
                Rule::unique('couriers', 'user_id')->where('company_id', $companyId)->ignore($courier),
            ],
        ], [
            'user_id.unique' => 'Este usuário já está vinculado a outro entregador.',
        ], ['name' => 'nome', 'phone' => 'telefone', 'user_id' => 'usuário']);

        if (isset($data['user_id']) && ! User::with('role')->find($data['user_id'])->hasPermission('deliveries.courier')) {
            throw ValidationException::withMessages(['user_id' => 'Este usuário não tem o perfil de entregador.']);
        }

        return [
            'name' => trim($data['name']),
            'phone' => $data['phone'] ?? null,
            'active' => (bool) ($data['active'] ?? true),
            'user_id' => $data['user_id'] ?? null,
        ];
    }
}
