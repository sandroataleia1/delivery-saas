<?php

use App\Http\Middleware\AuthenticatePrintAgent;
use App\Http\Middleware\EnsureAccountCanAccess;
use App\Http\Middleware\EnsureCompanyUser;
use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ForgetSessionUser;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);

        $middleware->alias([
            'account' => EnsureAccountCanAccess::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'company' => EnsureCompanyUser::class,
            'super' => EnsureSuperAdmin::class,
            'public.page' => ForgetSessionUser::class,
            'print.agent' => AuthenticatePrintAgent::class,
        ]);

        $middleware->validateCsrfTokens(except: ['webhooks/*', 'print-agent/*']);

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with('error', 'Sua sessão expirou. Tente novamente.');
            }

            $hidesDetails = in_array($status, [403, 404], true)
                || (in_array($status, [500, 503], true) && ! config('app.debug'));

            if ($hidesDetails && ! $request->expectsJson()) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
