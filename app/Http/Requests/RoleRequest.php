<?php

namespace App\Http\Requests;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('roles')->where('company_id', $this->user()->company_id)->ignore($this->route('role')),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => [Rule::in(Role::allPermissions())],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nome', 'description' => 'descrição', 'permissions' => 'permissões'];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $beyondOwn = array_diff((array) $this->input('permissions'), $this->user()->permissions());

            if ($beyondOwn !== []) {
                $validator->errors()->add('permissions', 'Você não pode conceder permissões que não possui.');
            }
        }];
    }
}
