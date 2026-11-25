<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Aluno\Models\Aluno;
use Modules\Avaliacao\Enums\FasePauta;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * As notas que o próprio aluno pode consultar: só de pautas DEFINITIVAS (o que o professor ainda pode corrigir não
 * aparece) e só as da sua matrícula. O aluno vem sempre da sessão, nunca de um id enviado pelo cliente.
 */
class NotasDoAlunoService
{
    public function __construct(
        private PautaFinalService $pautas,
        private PautaDocumentoService $colunasDaPauta,
    ) {
    }

    /** @return array{anos: list<array<string, mixed>>} */
    public function paraUtilizador(User $utilizador): array
    {
        return $this->paraPessoa($utilizador->dados_pessoa_id);
    }

    /** As notas definitivas da pessoa (ficha) de um aluno, com ou sem conta de login. */
    public function paraPessoa(?int $dadosPessoaId): array
    {
        $anos = [];

        foreach ($this->matriculasDaPessoa($dadosPessoaId) as $matricula) {
            $pauta = $this->pautaDefinitiva($matricula);
            if ($pauta === null) {
                continue;
            }

            [$campos, $rotulos] = $this->colunasDaPauta->colunas($pauta['pauta']);
            $aluno = $pauta['aluno'];

            $anos[] = [
                'matricula_id' => $matricula->id,
                'ano_lectivo' => (string) $matricula->anoLectivo?->nome,
                'turma' => (string) $pauta['pauta']['turma_rotulo'],
                'qualitativa' => ($pauta['pauta']['tipo'] ?? 'quantitativa') === 'qualitativa',
                'colunas' => $rotulos,
                'linhas' => array_map(fn (array $d) => [
                    'disciplina' => $d['nome'],
                    'valores' => array_map(fn (string $campo) => $aluno['celulas'][$d['id']][$campo] ?? null, $campos),
                ], array_values(array_filter($pauta['pauta']['disciplinas'], fn (array $d) => isset($aluno['celulas'][$d['id']])))),
                'resultado' => $aluno['decisao']['rotulo'] ?? null,
            ];
        }

        return ['anos' => $anos];
    }

    /** A matrícula só se o aluno da sessão for o dono e a pauta estiver definitiva; senão 404. */
    public function matriculaComBoletim(User $utilizador, int $matriculaId): Matricula
    {
        return $this->matriculaDaPessoaComBoletim($utilizador->dados_pessoa_id, $matriculaId);
    }

    public function matriculaDaPessoaComBoletim(?int $dadosPessoaId, int $matriculaId): Matricula
    {
        $matricula = $this->matriculasDaPessoa($dadosPessoaId)->firstWhere('id', $matriculaId);

        if ($matricula === null || $this->pautaDefinitiva($matricula) === null) {
            throw new ModelNotFoundException();
        }

        return $matricula;
    }

    /** @return \Illuminate\Support\Collection<int, Matricula> */
    private function matriculasDaPessoa(?int $dadosPessoaId)
    {
        $aluno = $dadosPessoaId === null ? null : Aluno::query()->where('dados_pessoa_id', $dadosPessoaId)->first();

        if ($aluno === null) {
            return collect();
        }

        return Matricula::query()->where('aluno_id', $aluno->id)->with('anoLectivo:id,nome')->orderByDesc('ano_lectivo_id')->get();
    }

    /**
     * A pauta definitiva onde o aluno consta: a do recurso quando já está aprovada e o aluno foi ao recurso, senão a da 1.ª fase.
     *
     * @return array{pauta: array<string, mixed>, aluno: array<string, mixed>}|null
     */
    private function pautaDefinitiva(Matricula $matricula): ?array
    {
        foreach ([FasePauta::RECURSO, FasePauta::EPOCA_NORMAL] as $fase) {
            if (! $this->pautas->definitiva($matricula->turma_id, $fase)) {
                continue;
            }

            $pauta = $this->pautas->paraTurma($matricula->turma_id, $fase);
            $aluno = collect($pauta['alunos'])->firstWhere('matricula_id', $matricula->id);

            if ($aluno !== null) {
                return ['pauta' => $pauta, 'aluno' => $aluno];
            }
        }

        return null;
    }
}
