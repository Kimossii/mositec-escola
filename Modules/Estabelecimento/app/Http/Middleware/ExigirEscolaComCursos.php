<?php

namespace Modules\Estabelecimento\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Estabelecimento\Models\Estabelecimento;
use Symfony\Component\HttpFoundation\Response;

/** Uma escola só de Creche, Pré-Escolar ou Primário não tem cursos: as rotas de cursos não existem para ela. */
class ExigirEscolaComCursos
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Estabelecimento::current()->usaCursos(), 404);

        return $next($request);
    }
}
