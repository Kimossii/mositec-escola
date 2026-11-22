<?php

namespace Modules\Licenciamento\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Contracts\IdentificaAdministradorDaEscola;
use Modules\Licenciamento\Enums\ModoDeOperacao;
use Modules\Licenciamento\Services\DireitosService;
use Symfony\Component\HttpFoundation\Response;

/**
 * `licenca.modulo:financeiro`: só deixa passar se o módulo não for licenciável (base) ou estiver contratado pela escola.
 * Caso contrário responde exactamente como uma rota inexistente (404 neutro, também em JSON/Inertia): não revela o produto.
 * Corre depois do `auth`; com a flag inactiva não consulta nada.
 * Excepção (as exportações do administrador): com a licença BLOQUEADA, o administrador da escola continua a chegar às rotas
 * de `rotas_permitidas_ao_admin` (o GateDeLicenca já restringiu tudo o resto); sem módulos contratados seriam todas 404 justamente quando importam.
 */
class ExigirModuloContratado
{
    public function __construct(
        private readonly DireitosService $direitos,
        private readonly IdentificaAdministradorDaEscola $administradores,
    ) {}

    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        if (! config('licenciamento.activo') || $this->direitos->moduloDisponivel($modulo)) {
            return $next($request);
        }

        abort_unless($this->exportacaoDoAdministradorBloqueado($request), 404);

        return $next($request);
    }

    private function exportacaoDoAdministradorBloqueado(Request $request): bool
    {
        $utilizador = $request->user('sanctum') ?? $request->user();
        $nome = $request->route()?->getName();

        return $utilizador !== null
            && $nome !== null
            && $this->direitos->modo() === ModoDeOperacao::BLOQUEADO
            && Str::is((array) config('licenciamento.rotas_permitidas_ao_admin', []), $nome)
            && $this->administradores->eAdministrador($utilizador);
    }
}
