<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UserRequest extends FormRequest
{
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users')->ignore($target)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('company_id', $this->user()->company_id)],
            'password' => [$target ? 'nullable' : 'required', 'confirmed', Password::defaults()],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'email' => 'e-mail', 'phone' => 'telefone', 'role_id' => 'perfil', 'password' => 'senha'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $role = Role::find($this->input('role_id'));
            /** @var User|null $target */
            $target = $this->route('user');

            if (! $this->user()->canHandleRole($role)) {
                $validator->errors()->add('role_id', 'Você não pode atribuir um perfil com mais permissões que o seu.');
            } elseif ($target?->isLastActiveAdmin() && ! $role->is_admin) {
                $validator->errors()->add('role_id', 'A empresa precisa de pelo menos um administrador ativo.');
            }
        }];
    }
}
