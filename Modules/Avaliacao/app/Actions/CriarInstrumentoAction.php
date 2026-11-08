<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Services\DefinicoesDeAvaliacaoService;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Services\RegraAvaliacaoService;
use Modules\Core\Traits\LogService;
use Modules\Disciplina\Models\Disciplina;
use Modules\Turma\Models\Turma;

/** Cria um instrumento. O período tem de ser do ano da turma e só há uma prova trimestral por (turma, disciplina, período). */
class CriarInstrumentoAction
{
    use LogService;

    public function __construct(private PautaFinalService $pautas, private RegraAvaliacaoService $regras, private DefinicoesDeAvaliacaoService $definicoes)
    {
    }

    public function executar(InstrumentoDTO $dto): AvaliacaoInstrumento
    {
        return $this->comLog('Criar instrumento de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($dto) {
            $turma = Turma::query()->with('nivelAcademico:id,codigo')->whereKey($dto->turma_id)->lockForUpdate()->firstOrFail();
            $this->pautas->exigirAberta($turma->id);
            Disciplina::query()->findOrFail($dto->disciplina_id);
            $periodo = Periodo::query()->findOrFail($dto->periodo_id);

            if ($periodo->ano_lectivo_id !== $turma->ano_lectivo_id) {
                throw ValidationException::withMessages(['periodo_id' => 'O período não pertence ao ano lectivo da turma.']);
            }

            if ($dto->tipo === TipoInstrumento::PT && AvaliacaoInstrumento::query()
                ->where('turma_id', $turma->id)->where('disciplina_id', $dto->disciplina_id)
                ->where('periodo_id', $periodo->id)->where('tipo', TipoInstrumento::PT->value)->exists()) {
                throw ValidationException::withMessages(['tipo' => 'Já existe uma prova trimestral para esta turma, disciplina e trimestre.']);
            }

            if ($dto->tipo === TipoInstrumento::MAC) {
                $maximo = $this->definicoes->maximo();

                if (AvaliacaoInstrumento::query()->where('turma_id', $turma->id)->where('disciplina_id', $dto->disciplina_id)
                    ->where('periodo_id', $periodo->id)->where('tipo', TipoInstrumento::MAC->value)->count() >= $maximo) {
                    throw ValidationException::withMessages(['tipo' => "A escola admite no máximo {$maximo} avaliações contínuas por trimestre."]);
                }
            }

            if ($dto->tipo === TipoInstrumento::QUALITATIVA) {
                throw ValidationException::withMessages(['tipo' => 'A avaliação qualitativa não usa avaliações criadas à mão.']);
            }

            if ($dto->tipo === TipoInstrumento::EXAME) {
                $this->validarExame($turma, $periodo, $dto->disciplina_id);
            }

            if ($dto->tipo === TipoInstrumento::RECURSO) {
                $this->validarRecurso($turma, $periodo, $dto->disciplina_id);
            }

            return AvaliacaoInstrumento::create([
                'turma_id' => $turma->id,
                'disciplina_id' => $dto->disciplina_id,
                'periodo_id' => $periodo->id,
                'tipo' => $dto->tipo,
                'nome' => $this->nomeDe($dto, $turma->id, $periodo->id),
                'obrigatorio' => $dto->obrigatorio,
            ]);
        }));
    }

    /** Sem nome indicado, a avaliação toma o do seu tipo: «Avaliação N» (contínuas) ou «Prova trimestral». */
    private function nomeDe(InstrumentoDTO $dto, int $turmaId, int $periodoId): string
    {
        $nome = trim($dto->nome);

        if ($nome !== '') {
            return $nome;
        }

        if ($dto->tipo !== TipoInstrumento::MAC) {
            return $dto->tipo->label();
        }

        $existentes = AvaliacaoInstrumento::query()->where('turma_id', $turmaId)->where('disciplina_id', $dto->disciplina_id)
            ->where('periodo_id', $periodoId)->where('tipo', TipoInstrumento::MAC->value)->count();

        return 'Avaliação '.($existentes + 1);
    }

    /** O exame próprio só existe nas classes que o têm, no 3.º trimestre, e é um por turma e disciplina. */
    private function validarExame(Turma $turma, Periodo $periodo, int $disciplinaId): void
    {
        if (! $this->regras->paraTurma($turma)?->com_exame) {
            throw ValidationException::withMessages(['tipo' => 'Esta classe não tem exame próprio.']);
        }

        if ($periodo->numero !== 3) {
            throw ValidationException::withMessages(['periodo_id' => 'O exame próprio pertence ao 3.º trimestre.']);
        }

        if (AvaliacaoInstrumento::query()->where('turma_id', $turma->id)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::EXAME->value)->exists()) {
            throw ValidationException::withMessages(['tipo' => 'Já existe um exame para esta turma e disciplina.']);
        }
    }

    /** O exame de recurso só existe da 6.ª à 9.ª, no 3.º trimestre, e é um por turma e disciplina. */
    private function validarRecurso(Turma $turma, Periodo $periodo, int $disciplinaId): void
    {
        if (! $this->regras->paraTurma($turma)?->com_recurso) {
            throw ValidationException::withMessages(['tipo' => 'Esta classe não tem exame de recurso.']);
        }

        if ($periodo->numero !== 3) {
            throw ValidationException::withMessages(['periodo_id' => 'O exame de recurso pertence ao 3.º trimestre.']);
        }

        if (AvaliacaoInstrumento::query()->where('turma_id', $turma->id)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::RECURSO->value)->exists()) {
            throw ValidationException::withMessages(['tipo' => 'Já existe um exame de recurso para esta turma e disciplina.']);
        }
    }
}
