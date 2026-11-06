<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoleRequest;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $actor = $request->user();

        return Inertia::render('roles/Index', [
            'roles' => Role::withCount('users')->orderByDesc('is_admin')->orderBy('name')->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'description' => $role->description,
                    'is_admin' => $role->is_admin,
                    'users_count' => $role->users_count,
                    'permissions_count' => count($role->grantedPermissions()),
                    'can_manage' => ! $role->is_admin
                        && $actor->hasPermission('roles.manage')
                        && $actor->canHandleRole($role),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('roles/Form', ['groups' => array_values(config('permissions.groups'))]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        Role::create($request->validated());

        return redirect()->route('roles.index')->with('success', 'Perfil criado.');
    }

    public function edit(Request $request, Role $role): Response
    {
        $this->authorizeTarget($request, $role);

        return Inertia::render('roles/Form', [
            'role' => $role->only('id', 'name', 'description', 'permissions'),
            'groups' => array_values(config('permissions.groups')),
        ]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->authorizeTarget($request, $role);

        $role->update($request->validated());

        return redirect()->route('roles.index')->with('success', 'Perfil atualizado.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeTarget($request, $role);

        if ($role->users()->exists()) {
            return back()->with('error', 'Este perfil tem usuários. Troque o perfil deles antes de excluir.');
        }

        $role->delete();

        return back()->with('success', 'Perfil excluído.');
    }

    /** O perfil Administrador é fixo e ninguém mexe em perfil acima do próprio. */
    private function authorizeTarget(Request $request, Role $role): void
    {
        abort_if($role->is_admin, 403);
        abort_unless($request->user()->canHandleRole($role), 403);
    }
}
