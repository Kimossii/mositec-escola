<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Core\Traits\LogService;
use Modules\Disciplina\Models\Disciplina;
use Modules\Turma\Models\NivelAcademico;

/** Cria ou actualiza a regra de (ano lectivo, classe). Valida pesos e mínimo antes de gravar. */
class GuardarRegraAvaliacaoAction
{
    use LogService;

    public function executar(RegraAvaliacaoDTO $dto): AvaliacaoRegra
    {
        return $this->comLog('Guardar regra de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            $this->validar($dto);

            return DB::transaction(function () use ($dto) {
                $regra = AvaliacaoRegra::updateOrCreate(
                ['ano_lectivo_id' => $dto->ano_lectivo_id, 'nivel_academico_id' => $dto->nivel_academico_id],
                [
                    'escala_maxima' => $dto->escala_maxima,
                    'nota_minima' => $dto->nota_minima,
                    'peso_mac' => $dto->peso_mac,
                    'peso_pt' => $dto->peso_pt,
                    'peso_mt1' => $dto->peso_mt1,
                    'peso_mt2' => $dto->peso_mt2,
                    'peso_mt3' => $dto->peso_mt3,
                    'peso_mfd_exame' => $dto->peso_mfd_exame,
                    'peso_exame' => $dto->peso_exame,
                    'recurso_max_disciplinas' => $dto->recurso_max_disciplinas,
                    'casas_decimais' => $dto->casas_decimais,
                    'modo_arredondamento' => $dto->modo_arredondamento,
                    'reprova_por_aproveitamento' => $dto->reprova_por_aproveitamento,
                ],
                );
                $regra->disciplinasChave()->syncWithPivotValues(array_values(array_unique($dto->disciplinas_chave)), ['tenant_id' => $regra->tenant_id]);

                return $regra;
            });
        });
    }

    private function validar(RegraAvaliacaoDTO $dto): void
    {
        AnoLectivo::query()->findOrFail($dto->ano_lectivo_id);
        foreach ($dto->disciplinas_chave as $disciplinaId) {
            Disciplina::query()->findOrFail($disciplinaId);
        }
        NivelAcademico::query()->findOrFail($dto->nivel_academico_id);

        if (bccomp(bcadd($dto->peso_mac, $dto->peso_pt, 2), '100', 2) !== 0) {
            throw ValidationException::withMessages(['peso_mac' => 'Os pesos da avaliação contínua e da prova trimestral têm de somar 100%.']);
        }

        if (bccomp(bcadd($dto->peso_mfd_exame, $dto->peso_exame, 2), '100', 2) !== 0) {
            throw ValidationException::withMessages(['peso_exame' => 'Os pesos da média final e do exame têm de somar 100%.']);
        }

        $pesosMt = [$dto->peso_mt1, $dto->peso_mt2, $dto->peso_mt3];
        $definidos = array_filter($pesosMt, fn ($p) => $p !== null);

        if ($definidos !== [] && count($definidos) !== 3) {
            throw ValidationException::withMessages(['peso_mt1' => 'Defina os pesos dos três trimestres, ou nenhum.']);
        }

        if ($definidos !== [] && bccomp(bcadd(bcadd($pesosMt[0], $pesosMt[1], 2), $pesosMt[2], 2), '100', 2) !== 0) {
            throw ValidationException::withMessages(['peso_mt1' => 'Os pesos dos trimestres têm de somar 100%.']);
        }

        if (bccomp($dto->nota_minima, (string) $dto->escala_maxima, 2) > 0) {
            throw ValidationException::withMessages(['nota_minima' => 'A nota mínima não pode exceder a escala máxima.']);
        }
    }
}
