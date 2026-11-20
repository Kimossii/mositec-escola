<?php

namespace Modules\Licenciamento\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Contracts\IdentificaAdministradorDaEscola;
use Symfony\Component\HttpFoundation\Response;

/** A página da licença só existe para o administrador da escola; para os restantes responde 404 (não revela a funcionalidade). */
class ApenasAdministradorDaEscola
{
    public function __construct(private readonly IdentificaAdministradorDaEscola $administradores) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        abort_unless($utilizador !== null && $this->administradores->eAdministrador($utilizador), 404);

        return $next($request);
    }
}
