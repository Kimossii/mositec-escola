<?php

namespace Modules\Portal\Services;

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
    public function dados(User $encarregado, ?User $educando): array
    {
        return [
            'educandos' => $this->educandos->listar($encarregado)->map(fn (User $e) => $this->cartao($e))->values()->all(),
            'educando' => $educando === null ? null : $this->cartao($educando),
            'acessos' => $this->permissoes->conjuntoDoPerfilAluno(),
        ];
    }

    /** @return array<string, mixed> */
    private function cartao(User $educando): array
    {
        return [
            'id' => $educando->id,
            'nome' => $educando->name,
            'numero_matricula' => $educando->numero_matricula,
            'parentesco' => $educando->pivot?->parentesco,
        ];
    }
}
