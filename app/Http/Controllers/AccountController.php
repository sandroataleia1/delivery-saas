<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('account/Edit', [
            'account' => $request->user()->only('name', 'email', 'phone'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->user()->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ], [], ['name' => 'nome', 'phone' => 'telefone']));

        return back()->with('success', 'Dados atualizados.');
    }

    public function password(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()],
        ], [], ['current_password' => 'senha atual', 'password' => 'nova senha']);

        $user = $request->user();
        $user->password = $validated['password'];
        $user->must_change_password = false;
        $user->saveQuietly();

        $user->terminateSessions(except: $request->session()->getId());
        Audit::record('password_changed', $user, "{$user->name} alterou a própria senha");

        return redirect()->route('account.edit')->with('success', 'Senha alterada.');
    }
}
