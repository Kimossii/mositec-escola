<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CabecalhosDeSeguranca
{
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        // `setIfNotSet`: um controller (ex.: download de ficheiro) que já definiu o seu cabeçalho mantém-no.
        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ] as $nome => $valor) {
            if (!$resposta->headers->has($nome)) {
                $resposta->headers->set($nome, $valor);
            }
        }

        if (config('seguranca.forcar_https') && $request->isSecure()) {
            $resposta->headers->set('Strict-Transport-Security', 'max-age=' . config('seguranca.hsts_max_age'));
        }

        if (config('seguranca.csp_report_only')) {
            $resposta->headers->set('Content-Security-Policy-Report-Only', "default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'self'");
        }

        return $resposta;
    }
}
