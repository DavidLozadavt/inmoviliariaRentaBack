<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Encabezados para Cloudflare: lo público se puede cachear poco tiempo,
 * todo lo demás queda privado y sin caché.
 */
class Visor3dCacheHeaders
{
    public function handle(Request $request, Closure $next, string $mode = 'private'): Response
    {
        $response = $next($request);

        if ($mode === 'public' && $response->isSuccessful()) {
            $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=120');
            $response->headers->remove('Set-Cookie');
        } else {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        }

        $response->headers->set('Vary', 'Authorization, Accept-Encoding');

        return $response;
    }
}
