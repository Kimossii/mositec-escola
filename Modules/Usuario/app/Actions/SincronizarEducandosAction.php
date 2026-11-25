<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\Contracts\ProcuraAlunoParaConta;
use Modules\Core\DTO\AlunoParaConta;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Traits\LogService;
use Modules\Usuario\Models\User;

/** Fixa os educandos de um encarregado: acrescenta os novos, remove os que saíram e mantém o parentesco dos que ficam. */
class SincronizarEducandosAction
{
    use LogService;

    public function __construct(
        private readonly ProcuraAlunoParaConta $alunos,
        private readonly RegistarAuditoriaTenantAction $auditoria,
    ) {
    }

    /**
     * Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`.
     *
     * @param  list<string>  $matriculas  matrículas oficiais dos alunos; a lista completa que o encarregado deve ficar a ter
     */
    public function executar(User $encarregado, array $matriculas): void
    {
        $this->comLog('Sincronizar educandos do encarregado', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($encarregado, $matriculas));
    }

    private function executarSemRegisto(User $encarregado, array $matriculas): void
    {
        $pessoas = [];

        foreach (array_unique($matriculas) as $matricula) {
            $aluno = $this->alunos->procurar((string) $matricula);

            if ($aluno === null) {
                throw ValidationException::withMessages(['matriculas_educandos' => 'Uma das matrículas indicadas não existe.']);
            }

            $pessoas[] = $aluno->dadosPessoaId;
        }

        DB::transaction(function () use ($encarregado, $pessoas) {
            $antes = $encarregado->educandos()->pluck('dados_pessoas.id')->map(fn ($id) => (int) $id)->all();

            if ($this->mesmoConjunto($antes, $pessoas)) {
                return;
            }

            $encarregado->educandos()->sync($pessoas);

            $this->auditoria->executar(new AuditoriaTenantDTO(
                'encarregado.educandos_alterados',
                'users',
                (int) $encarregado->id,
                ['matriculas' => $this->matriculasDe($antes)],
                ['matriculas' => $this->matriculasDe($pessoas)],
            ));
        });
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     */
    private function mesmoConjunto(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * @param  list<int>  $dadosPessoaIds
     * @return list<string>
     */
    private function matriculasDe(array $dadosPessoaIds): array
    {
        return array_map(fn (AlunoParaConta $a) => $a->numeroMatricula, $this->alunos->porPessoas($dadosPessoaIds));
    }
}
