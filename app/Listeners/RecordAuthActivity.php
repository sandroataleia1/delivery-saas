<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

/** Eventos de autenticação na auditoria. Registrado por descoberta automática. */
class RecordAuthActivity
{
    public function handleLogin(Login $event): void
    {
        $user = $event->user;
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        Audit::record('login', $user, "{$user->name} entrou no sistema", actor: $user);
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            Audit::record('logout', $event->user, "{$event->user->name} saiu do sistema", actor: $event->user);
        }
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? '';

        // Quem tentou não se autenticou: a tentativa não pode ser atribuída ao dono da conta.
        // O registro continua ligado à conta e à empresa alvo pelo assunto.
        Audit::record(
            'login_failed',
            $event->user,
            "Login recusado para $email",
            new: ['email' => $email],
            actorName: 'Visitante não autenticado',
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        Audit::record('password_reset', $event->user, "{$event->user->name} redefiniu a senha por e-mail", actor: $event->user);
    }
}
