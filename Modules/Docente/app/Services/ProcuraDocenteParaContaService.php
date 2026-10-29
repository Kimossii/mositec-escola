<?php

namespace Modules\Docente\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Contracts\ProcuraDocenteParaConta;
use Modules\Core\DTO\DocenteParaConta;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;

/**
 * Implementação, no módulo Docente, do contrato que o Usuario usa para procurar o docente de uma conta. Lê pelo model
 * (scope de tenant); «sem conta» é não haver `User` com a mesma pessoa (a relação `conta`).
 */
class ProcuraDocenteParaContaService implements ProcuraDocenteParaConta
{
    public function procurar(int $docenteId): ?DocenteParaConta
    {
        $docente = Docente::with('dadosPessoa')->whereKey($docenteId)->first();

        return $docente === null || $docente->dadosPessoa === null ? null : $this->paraConta($docente);
    }

    public function semConta(string $pesquisa = '', int $limite = 20): array
    {
        $pesquisa = trim($pesquisa);

        return Docente::query()
            ->with('dadosPessoa')
            ->where('estabelecimento_id', Estabelecimento::current()?->id)
            ->where('estado', 1)
            ->whereHas('dadosPessoa')
            ->whereDoesntHave('conta')
            ->when($pesquisa !== '', fn (Builder $q) => $q->whereHas('dadosPessoa', fn ($p) => $p
                ->whereContem('nome_completo', $pesquisa)
                ->orWhereContem('numero_identificacao', $pesquisa)))
            ->orderBy(DadosPessoa::query()->select('nome_completo')->whereColumn('dados_pessoas.id', 'docentes.dados_pessoa_id'))
            ->limit($limite)
            ->get()
            ->map(fn (Docente $d) => $this->paraConta($d))
            ->all();
    }

    private function paraConta(Docente $docente): DocenteParaConta
    {
        return new DocenteParaConta(
            id: $docente->id,
            nome: $docente->dadosPessoa->nome_completo,
            dadosPessoaId: $docente->dados_pessoa_id,
            estado: (int) $docente->estado,
            numeroIdentificacao: $docente->dadosPessoa->numero_identificacao,
            email: $docente->dadosPessoa->email,
        );
    }
}
