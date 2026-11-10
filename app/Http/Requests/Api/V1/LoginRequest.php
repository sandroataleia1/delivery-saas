<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\LoginRequest as WebLoginRequest;

/** Login do app: as regras do login web mais o nome do aparelho, que batiza o token. */
class LoginRequest extends WebLoginRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [...parent::rules(), 'device_name' => ['required', 'string', 'max:100']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'device_name' => 'aparelho'];
    }
}
