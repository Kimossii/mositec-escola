<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Matricula\Models\Matricula;

class RenovarMatriculasEmMassaAction
{
    public function __construct(
        private RenovarMatriculaAction $renovarMatricula,
    ) {
    }

    /**
     * Renova cada matrícula independentemente — uma falha (ex.: sem turma
     * seguinte sugerível) não interrompe as restantes, porque cada
     * RenovarMatriculaAction já corre na sua própria transacção.
     *
     * @param  int[]  $matriculaIds
     * @return array{sucesso: int, falhas: array<int, string>}
     */
    public function executar(array $matriculaIds, ?int $utilizadorId = null): array
    {
        $sucesso = 0;
        $falhas = [];

        foreach ($matriculaIds as $matriculaId) {
            $matricula = Matricula::find($matriculaId);

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
                Log::warning("Renovação em massa: falha inesperada na matrícula {$matriculaId}: {$e->getMessage()}");
                $falhas[$matriculaId] = 'Erro inesperado ao renovar.';
            }
        }

        return ['sucesso' => $sucesso, 'falhas' => $falhas];
    }
}
