<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\DTO\LancarNotasEmLoteDTO;
use Modules\Core\Traits\LogService;
use Modules\Matricula\Models\InscricaoDisciplina;

/**
 * Grava de uma vez tudo o que o professor alterou no ecrã: ou fica tudo, ou, se uma nota for recusada, não fica nenhuma e
 * a mensagem diz de que aluno é. Cada nota passa pelas mesmas regras, prazos e permissões do lançamento individual.
 */
class LancarNotasEmLoteAction
{
    use LogService;

    public function __construct(
        private LancarNotaAction $lancarNota,
        private LancarExameAction $lancarExame,
        private LancarRecursoAction $lancarRecurso,
    ) {
    }

    /** @return int quantas notas se gravaram */
    public function executar(LancarNotasEmLoteDTO $dto): int
    {
        return $this->comLog('Lançar notas em lote', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(LancarNotasEmLoteDTO $dto): int
    {
        $itens = [
            ...array_map(fn ($n) => [$n->inscricao_disciplina_id, fn () => $this->lancarNota->executar($n)], $dto->notas),
            ...array_map(fn ($n) => [$n->inscricao_disciplina_id, fn () => $this->lancarExame->executar($n)], $dto->exames),
            ...array_map(fn ($n) => [$n->inscricao_disciplina_id, fn () => $this->lancarRecurso->executar($n)], $dto->recursos),
        ];

        DB::transaction(function () use ($itens) {
            foreach ($itens as [$inscricaoId, $gravar]) {
                try {
                    $gravar();
                } catch (ValidationException $e) {
                    $nome = InscricaoDisciplina::query()->with('matricula.aluno.dadosPessoa:id,nome_completo')->find($inscricaoId)?->matricula?->aluno?->dadosPessoa?->nome_completo ?? 'aluno';

                    throw ValidationException::withMessages(['lote' => $nome.': '.collect($e->errors())->flatten()->first()]);
                }
            }
        });

        return count($itens);
    }
}
