<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Core\Traits\LogService;
use Modules\Disciplina\Models\Disciplina;
use Modules\Turma\Models\Turma;

/** Cria um instrumento. O período tem de ser do ano da turma e só há uma prova trimestral por (turma, disciplina, período). */
class CriarInstrumentoAction
{
    use LogService;

    public function executar(InstrumentoDTO $dto): AvaliacaoInstrumento
    {
        return $this->comLog('Criar instrumento de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($dto) {
            $turma = Turma::query()->whereKey($dto->turma_id)->lockForUpdate()->firstOrFail();
            Disciplina::query()->findOrFail($dto->disciplina_id);
            $periodo = Periodo::query()->findOrFail($dto->periodo_id);

            if ($periodo->ano_lectivo_id !== $turma->ano_lectivo_id) {
                throw ValidationException::withMessages(['periodo_id' => 'O período não pertence ao ano lectivo da turma.']);
            }

            if (trim($dto->nome) === '') {
                throw ValidationException::withMessages(['nome' => 'Indique o nome da avaliação.']);
            }

            if ($dto->tipo === TipoInstrumento::PT && AvaliacaoInstrumento::query()
                ->where('turma_id', $turma->id)->where('disciplina_id', $dto->disciplina_id)
                ->where('periodo_id', $periodo->id)->where('tipo', TipoInstrumento::PT->value)->exists()) {
                throw ValidationException::withMessages(['tipo' => 'Já existe uma prova trimestral para esta turma, disciplina e trimestre.']);
            }

            return AvaliacaoInstrumento::create([
                'turma_id' => $turma->id,
                'disciplina_id' => $dto->disciplina_id,
                'periodo_id' => $periodo->id,
                'tipo' => $dto->tipo,
                'nome' => $dto->nome,
                'obrigatorio' => $dto->obrigatorio,
            ]);
        }));
    }
}
