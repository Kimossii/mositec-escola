<?php

namespace Modules\Portal\Services;

use Modules\Aluno\Models\Aluno;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;

/** Dados da página de início do portal: os educandos, o escolhido (se houver) e o que o perfil Aluno permite ver. */
class PortalInicioService
{
    public function __construct(
        private EducandosDoEncarregadoService $educandos,
        private PermissionResolver $permissoes,
    ) {
    }

    /**
     * @return array{educandos: list<array<string, mixed>>, educando: ?array<string, mixed>, acessos: list<string>}
     */
    public function dados(User $encarregado, ?Aluno $educando): array
    {
        return [
            'educandos' => $this->educandos->listar($encarregado)->map(fn (Aluno $e) => $this->cartao($e))->values()->all(),
            'educando' => $educando === null ? null : $this->cartao($educando),
            'acessos' => $this->permissoes->conjuntoDoPerfilAluno(),
        ];
    }

    /** @return array<string, mixed> */
    private function cartao(Aluno $educando): array
    {
        return [
            'id' => $educando->id,
            'nome' => $educando->dadosPessoa?->nome_completo,
            'numero_matricula' => $educando->numero_matricula,
            'parentesco' => $educando->parentesco,
        ];
    }
}
