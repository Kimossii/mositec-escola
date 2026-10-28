<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Models\Matricula;
use Modules\Core\Traits\LogService;

class RenovarMatriculasEmMassaAction
{
    use LogService;

    public function __construct(
        private RenovarMatriculaAction $renovarMatricula,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(array $matriculaIds, ?int $utilizadorId = null): array
    {
        return $this->comLog('Renovar matrículas em massa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matriculaIds, $utilizadorId));
    }

    /**
     * Renova cada matrícula independentemente — uma falha (ex.: sem turma
     * seguinte sugerível) não interrompe as restantes, porque cada
     * RenovarMatriculaAction já corre na sua própria transacção.
     *
     * As matrículas são carregadas de uma vez com `whereIn` (antes: uma
     * query `find()` por id, N+1), com as relações que RenovarMatriculaAction
     * acaba sempre por tocar já pré-carregadas — evita repetir esse
     * carregamento a cada iteração do ciclo.
     *
     * @param  int[]  $matriculaIds
     * @return array{sucesso: int, falhas: array<int, string>}
     */
    private function executarSemRegisto(array $matriculaIds, ?int $utilizadorId = null): array
    {
        $sucesso = 0;
        $falhas = [];

        $matriculas = Matricula::whereIn('id', $matriculaIds)
            ->with(['turma.nivelAcademico', 'turma.curso', 'anoLectivo', 'aluno'])
            ->get()
            ->keyBy('id');

        foreach ($matriculaIds as $matriculaId) {
            $matricula = $matriculas->get($matriculaId);

            if ($matricula === null) {
                $falhas[$matriculaId] = 'Matrícula não encontrada.';
                continue;
            }

            try {
                $this->renovarMatricula->executar($matricula, null, $utilizadorId);
                $sucesso++;
            } catch (ValidationException $e) {
                $falhas[$matriculaId] = collect($e->errors())->flatten()->first();
            } catch (\Throwable $e) {
                $this->logError('Renovação em massa: falha inesperada na matrícula', ['matricula_id' => $matriculaId, 'excecao' => $e]);
                $falhas[$matriculaId] = 'Erro inesperado ao renovar.';
            }
        }

        return ['sucesso' => $sucesso, 'falhas' => $falhas];
    }
}
