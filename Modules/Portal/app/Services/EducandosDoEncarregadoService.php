<?php

namespace Modules\Portal\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\TenantContext;
use Modules\Usuario\Models\User;

/**
 * Ponto único de acesso aos educandos de um encarregado. O encarregado liga-se à ficha do aluno (que pode não ter
 * conta de login) e só vê os alunos desta escola com a ficha activa. É aqui que entram, no futuro, outras regras
 * de acesso (ex.: atraso de propina); nunca em cada página.
 *
 * Cada educando é um `Aluno` com `dadosPessoa` carregado e o atributo `parentesco` da ligação.
 */
class EducandosDoEncarregadoService
{
    public function __construct(private TenantContext $contexto)
    {
    }

    /** @return Collection<int, Aluno> */
    public function listar(User $encarregado): Collection
    {
        return $this->consulta($encarregado)->get()
            ->sortBy(fn (Aluno $a) => mb_strtolower((string) $a->dadosPessoa?->nome_completo))
            ->values();
    }

    public function obter(User $encarregado, int $alunoId): ?Aluno
    {
        return $this->consulta($encarregado)->where('alunos.id', $alunoId)->first();
    }

    /** @return Builder<Aluno> */
    private function consulta(User $encarregado): Builder
    {
        return Aluno::query()
            ->join('encarregados_alunos as ea', 'ea.dados_pessoa_id', '=', 'alunos.dados_pessoa_id')
            ->where('ea.encarregado_id', $encarregado->id)
            ->where('ea.tenant_id', $this->contexto->id())
            ->where('alunos.estado', Estado::ATIVO->value)
            ->with('dadosPessoa')
            ->select('alunos.*', 'ea.parentesco');
    }
}
