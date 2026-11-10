<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => 'e-mail', 'password' => 'senha'];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // A mesma mensagem para senha errada e conta inativa: não revela quem existe.
        $authenticated = Auth::attemptWhen(
            $this->only('email', 'password'),
            fn ($user) => $user->canSignIn(),
            $this->boolean('remember'),
        );

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Mesma checagem do login web (limite de tentativas, conta ativa, auditoria),
     * sem abrir sessão: o app recebe um token em vez de cookie.
     */
    public function authenticateWithoutSession(): User
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');
        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user instanceof User || ! $provider->validateCredentials($user, $credentials) || ! $user->canSignIn()) {
            RateLimiter::hit($this->throttleKey());
            event(new Failed('sanctum', $user, $credentials));

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());
        event(new Login('sanctum', $user, false));

        return $user;
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::lower($this->string('email')).'|'.$this->ip();
    }
}
