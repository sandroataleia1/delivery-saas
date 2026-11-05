<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only('search', 'status', 'role');
        $actor = $request->user();

        $users = User::query()
            ->with('role')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(
                fn ($query) => $query->where('name', 'like', "%$search%")->orWhere('email', 'like', "%$search%")
            ))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role_id', $role))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('active', $status === 'ativos'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role?->name,
                'active' => $user->active,
                'last_login_at' => $user->last_login_at?->format('d/m/Y H:i'),
                'is_self' => $user->is($actor),
                'can_manage' => $actor->hasPermission('users.manage') && $actor->canHandleRole($user->role),
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('users/Form', ['roles' => $this->assignableRoles($request->user())]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only('name', 'email', 'phone', 'password'));
        $user->role_id = $request->validated('role_id');
        $user->must_change_password = true;
        $user->save();

        return redirect()->route('users.index')
            ->with('success', "Usuário {$user->name} criado. No primeiro acesso será pedida uma nova senha.");
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorizeTarget($request->user(), $user);

        return Inertia::render('users/Form', [
            'user' => $user->only('id', 'name', 'email', 'phone', 'role_id'),
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request->user(), $user);

        $user->fill($request->safe()->only('name', 'email', 'phone'));
        $user->role_id = $request->validated('role_id');

        $newPassword = $request->validated('password');

        if ($newPassword) {
            $user->password = $newPassword;
            $user->must_change_password = true;
        }

        $user->save();

        if ($newPassword) {
            $user->terminateSessions(except: $user->is($request->user()) ? $request->session()->getId() : null);
        }

        return redirect()->route('users.index')->with('success', 'Usuário atualizado.');
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request->user(), $user);

        if ($user->is($request->user())) {
            return back()->with('error', 'Você não pode inativar o próprio usuário.');
        }

        $user->active = ! $user->active;
        $user->save();

        return back()->with('success', $user->active ? 'Usuário ativado.' : 'Usuário inativado.');
    }

    private function authorizeTarget(User $actor, User $target): void
    {
        abort_unless($actor->canHandleRole($target->role), 403);
    }

    private function assignableRoles(User $actor)
    {
        return Role::orderBy('name')->get()
            ->filter(fn (Role $role) => $actor->canHandleRole($role))
            ->map(fn (Role $role) => $role->only('id', 'name', 'description'))
            ->values();
    }
}
