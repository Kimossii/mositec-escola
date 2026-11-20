<?php

namespace Modules\Financeiro\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;
use Modules\Financeiro\Services\AplicarMultasDiariasService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Na primeira visita autenticada do dia a um tenant, aplica as multas por atraso (ver AplicarMultasDiariasService).
 * Só em GET: nunca atrasa nem interfere com gravações. Sem lógica de negócio aqui.
 */
class AplicarMultasDoDia
{
    public function __construct(private AplicarMultasDiariasService $multas, private ModulosDisponiveis $modulos)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->user() !== null && $this->modulos->disponivel('financeiro')) {
            $this->multas->executarSeNecessario();
        }

        return $next($request);
    }
}
