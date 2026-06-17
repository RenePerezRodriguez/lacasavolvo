<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->append(SecurityHeaders::class);
        // API pura: toda request api/* se trata como JSON. Sin esto, una request sin
        // `Accept: application/json` que falla auth ejecutaba redirect()->route('login')
        // —ruta inexistente— y devolvía 500 (HTML) en vez de 401. Forzar el header hace
        // que expectsJson() sea true → Auth/Validation/HttpException responden JSON.
        $middleware->api(prepend: [\App\Http\Middleware\ForceJsonResponse::class]);
        $middleware->alias([
            'role'       => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // For API routes, render HttpException (403, 404, 422 abort_if, etc.) as JSON.
        // AuthenticationException, ValidationException fall through to Laravel's defaults (401/422).
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $msg = $e->getMessage() ?: (\Symfony\Component\HttpFoundation\Response::$statusTexts[$e->getStatusCode()] ?? 'Error');
                return response()->json(['message' => $msg], $e->getStatusCode());
            }
        });
    })->create();
