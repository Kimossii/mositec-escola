<?php

namespace Modules\Docente\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Docente\Support\SobreposicaoDeAtribuicao;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Turma\Models\Turma;

/**
 * Regra 9 do desenho: só papel e vigência de atribuições agendadas ou em curso; professor, turma e disciplina não
 * mudam. Revalida a sobreposição ignorando a própria atribuição.
 */
class AtualizarAtribuicaoAction
{
    use LogService;

    public function __construct(
        private ValidadorAtribuicaoDocenteService $validador,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(AtribuicaoDocente $atribuicao, PapelDocente $papel, string $dataInicio, ?string $dataFim): AtribuicaoDocente
    {
        return $this->comLog('Actualizar atribuição docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($atribuicao, $papel, $dataInicio, $dataFim));
    }

    private function executarSemRegisto(AtribuicaoDocente $atribuicao, PapelDocente $papel, string $dataInicio, ?string $dataFim): AtribuicaoDocente
    {
        try {
            return DB::transaction(fn () => $this->actualizar($atribuicao, $papel, $dataInicio, $dataFim));
        } catch (QueryException $e) {
            throw SobreposicaoDeAtribuicao::comoErroDeValidacao($e) ?? $e;
        }
    }

    private function actualizar(AtribuicaoDocente $atribuicao, PapelDocente $papel, string $dataInicio, ?string $dataFim): AtribuicaoDocente
    {
        Turma::query()->whereKey($atribuicao->turma_id)->lockForUpdate()->first();
        $this->validador->exigirAnoAberto((int) $atribuicao->turma_id);

        // Estado relido já com a turma bloqueada.
        $actual = AtribuicaoDocente::query()->findOrFail($atribuicao->getKey());
        $hoje = $this->relogio->hoje();
        $estado = $actual->estadoNaData($hoje);

        if ($estado === 'terminada') {
            throw ValidationException::withMessages(['atribuicao' => 'Uma atribuição terminada não se altera; crie uma nova.']);
        }

        // Uma atribuição já iniciada não volta a «agendada» (senão contornaria a regra de que só se elimina o nunca iniciado)
        // nem termina retroactivamente (para terminar, usa-se Encerrar).
        if ($estado === 'em_curso' && $dataInicio > $hoje->toDateString()) {
            throw ValidationException::withMessages(['data_inicio' => 'Uma atribuição em curso não pode passar a começar no futuro.']);
        }

        if ($dataFim !== null && $dataFim < $hoje->toDateString()) {
            throw ValidationException::withMessages(['data_fim' => 'A data de fim não pode ser anterior a hoje; para terminar a atribuição use "Encerrar".']);
        }

        $this->validador->validar(new AtribuicaoDocenteDTO(
            professor_id: (int) $actual->professor_id,
            turma_id: (int) $actual->turma_id,
            disciplina_id: (int) $actual->disciplina_id,
            papel: $papel,
            data_inicio: $dataInicio,
            data_fim: $dataFim,
        ), $actual);

        $alteracoes = ['papel' => $papel, 'data_inicio' => $dataInicio, 'data_fim' => $dataFim];
        // Vigência de novo aberta: o motivo do encerramento deixa de se aplicar.
        if ($dataFim === null) {
            $alteracoes['motivo_encerramento'] = null;
        }

        $actual->update($alteracoes);

        return $actual;
    }
}
