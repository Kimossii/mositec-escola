<?php

namespace Modules\Portal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Portal\Services\EducandosDoEncarregadoService;
use Symfony\Component\HttpFoundation\Response;

/**
 * `portal.educando[:permissao,...]`: o `{educando}` da rota tem de ser filho do utilizador (senão 404, igual a um id
 * inexistente) e, se indicadas, o perfil Aluno tem de ter essas permissões (senão 403): o encarregado vê o que o aluno vê.
 */
class EducandoDoEncarregado
{
    public function __construct(
        private EducandosDoEncarregadoService $educandos,
        private PermissionResolver $permissoes,
    ) {
    }

    public function handle(Request $request, Closure $next, string ...$permissoes): Response
    {
        $id = $request->route('educando');
        $educando = $request->user() !== null && is_numeric($id) ? $this->educandos->obter($request->user(), (int) $id) : null;

        abort_if($educando === null, 404);

        $herdadas = $this->permissoes->conjuntoDoPerfilAluno();
        abort_if(array_diff($permissoes, $herdadas) !== [], 403);

        $request->attributes->set('educando', $educando);

        return $next($request);
    }
}
