<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fuerza la negociación de contenido JSON en las rutas api/*.
 *
 * Esta es una API pura (sin vistas web). Al setear `Accept: application/json`
 * antes del pipeline de auth, `Request::expectsJson()` siempre es true, de modo
 * que un fallo de autenticación devuelve 401 JSON en lugar de intentar
 * `redirect()->route('login')` (ruta inexistente → RouteNotFoundException → 500).
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
