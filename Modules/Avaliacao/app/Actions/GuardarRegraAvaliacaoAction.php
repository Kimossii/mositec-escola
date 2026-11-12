<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\DTO\LigacaoDeClassesDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraNivel;
use Modules\Avaliacao\Services\RegraPadraoService;
use Modules\Core\Traits\LogService;
use Modules\Disciplina\Models\Disciplina;
use Modules\Turma\Models\NivelAcademico;

/** Cria ou actualiza uma regra de avaliação de um ano lectivo e liga-lhe as classes. Valida pesos e mínimo antes de gravar. */
class GuardarRegraAvaliacaoAction
{
    use LogService;

    public function __construct(private LigarClassesARegraAction $ligar, private RegraPadraoService $padrao)
    {
    }

    public function executar(RegraAvaliacaoDTO $dto): AvaliacaoRegra
    {
        return $this->comLog('Guardar regra de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            $this->validar($dto);

            return DB::transaction(function () use ($dto) {
                $classes = NivelAcademico::query()->whereIn('id', $dto->nivel_ids)->orderBy('ordem')->get();
                $regra = $this->regraExistente($dto);
                $padrao = $classes->map(fn (NivelAcademico $n) => $this->padrao->regraDe((string) $n->codigo))->filter();

                $campos = [
                    'ano_lectivo_id' => $dto->ano_lectivo_id,
                    'nome' => trim($dto->nome) !== '' ? trim($dto->nome) : ($regra?->nome ?: (string) ($classes->first()?->nome ?? 'Nova regra')),
                    'escala_maxima' => $dto->escala_maxima,
                    'nota_minima' => $dto->nota_minima,
                    'peso_mac' => $dto->peso_mac,
                    'peso_pt' => $dto->peso_pt,
                    'sigla_prova' => $dto->sigla_prova,
                    'peso_mt1' => $dto->peso_mt1,
                    'peso_mt2' => $dto->peso_mt2,
                    'peso_mt3' => $dto->peso_mt3,
                    'peso_mfd_exame' => $dto->peso_mfd_exame,
                    'peso_exame' => $dto->peso_exame,
                    'recurso_max_disciplinas' => $dto->recurso_max_disciplinas,
                    'casas_decimais' => $dto->casas_decimais,
                    'modo_arredondamento' => $dto->modo_arredondamento,
                    'tratamento_negativas' => $dto->tratamento_negativas,
                    // Sem indicação expressa, valem as opções por defeito das classes da regra.
                    'com_exame' => $dto->com_exame ?? $padrao->contains(fn (array $p) => $p['com_exame']),
                    'com_recurso' => $dto->com_recurso ?? $padrao->contains(fn (array $p) => $p['com_recurso']),
                    'exame_usa_mfd_arredondada' => $dto->exame_usa_mfd_arredondada,
                ];

                $regra = $regra === null ? AvaliacaoRegra::create($campos) : tap($regra)->update($campos);
                $regra->disciplinasChave()->syncWithPivotValues(array_values(array_unique($dto->disciplinas_chave)), ['tenant_id' => $regra->tenant_id]);
                $this->ligar->executar(new LigacaoDeClassesDTO($dto->ano_lectivo_id, $dto->nivel_ids, false, $regra->id));

                return $regra;
            });
        });
    }

    /** A regra indicada ou, sem indicação, a que a primeira classe já segue neste ano. */
    private function regraExistente(RegraAvaliacaoDTO $dto): ?AvaliacaoRegra
    {
        if ($dto->regra_id !== null) {
            $regra = AvaliacaoRegra::query()->findOrFail($dto->regra_id);

            if ($regra->ano_lectivo_id !== $dto->ano_lectivo_id) {
                throw ValidationException::withMessages(['regra_id' => 'A regra não pertence a este ano lectivo.']);
            }

            return $regra;
        }

        if ($dto->nova) {
            return null;
        }

        $ligacao = AvaliacaoRegraNivel::query()->where('ano_lectivo_id', $dto->ano_lectivo_id)->whereIn('nivel_academico_id', $dto->nivel_ids)->whereNotNull('regra_id')->first();

        return $ligacao?->regra;
    }

    private function validar(RegraAvaliacaoDTO $dto): void
    {
        AnoLectivo::query()->findOrFail($dto->ano_lectivo_id);
        foreach ($dto->disciplinas_chave as $disciplinaId) {
            Disciplina::query()->findOrFail($disciplinaId);
        }

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
