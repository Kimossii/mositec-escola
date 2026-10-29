<?php

namespace Modules\Docente\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Traits\LogService;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Docente\Support\SobreposicaoDeAtribuicao;
use Modules\Turma\Models\Turma;

/**
 * Cria uma atribuição docente (regras 1 a 6 do desenho). Bloqueia a linha da turma antes de validar, para que
 * duas atribuições à mesma turma sejam validadas uma de cada vez; em PostgreSQL as constraints de exclusão
 * cobrem o que escapar a isso e a violação é devolvida como erro de validação.
 */
class AtribuirDocenteAction
{
    use LogService;

    public function __construct(private ValidadorAtribuicaoDocenteService $validador)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(AtribuicaoDocenteDTO $dto): AtribuicaoDocente
    {
        return $this->comLog('Atribuir docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(AtribuicaoDocenteDTO $dto): AtribuicaoDocente
    {
        try {
            return DB::transaction(fn () => $this->gravar($dto));
        } catch (QueryException $e) {
            // Já FORA da transacção (ou do savepoint, se houver uma exterior), revertida.
            throw SobreposicaoDeAtribuicao::comoErroDeValidacao($e) ?? $e;
        }
    }

    private function gravar(AtribuicaoDocenteDTO $dto): AtribuicaoDocente
    {
        Turma::query()->whereKey($dto->turma_id)->lockForUpdate()->first();

        $turma = $this->validador->validar($dto);

        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'turma_id' => $turma->id,
            'disciplina_id' => $dto->disciplina_id,
            'docente_id' => $dto->docente_id,
            'papel' => $dto->papel,
            'data_inicio' => $dto->data_inicio,
            'data_fim' => $dto->data_fim,
        ]);
    }
}
