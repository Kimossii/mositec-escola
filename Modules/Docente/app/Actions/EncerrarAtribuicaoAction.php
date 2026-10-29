<?php

namespace Modules\Docente\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Turma\Models\Turma;

/** Regra 8 do desenho: define `data_fim` (hoje ou futura) e o motivo. Uma atribuição terminada não se reabre. */
class EncerrarAtribuicaoAction
{
    use LogService;

    public function __construct(
        private ValidadorAtribuicaoDocenteService $validador,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(AtribuicaoDocente $atribuicao, string $dataFim, string $motivo): AtribuicaoDocente
    {
        return $this->comLog('Encerrar atribuição docente', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($atribuicao, $dataFim, $motivo));
    }

    private function executarSemRegisto(AtribuicaoDocente $atribuicao, string $dataFim, string $motivo): AtribuicaoDocente
    {
        return DB::transaction(function () use ($atribuicao, $dataFim, $motivo) {
            Turma::query()->whereKey($atribuicao->turma_id)->lockForUpdate()->first();
            $turma = $this->validador->exigirAnoAberto((int) $atribuicao->turma_id);

            // Estado relido já com a turma bloqueada.
            $actual = AtribuicaoDocente::query()->findOrFail($atribuicao->getKey());
            $hoje = $this->relogio->hoje();

            if ($actual->estadoNaData($hoje) === 'terminada') {
                throw ValidationException::withMessages(['atribuicao' => 'Uma atribuição terminada não se altera; crie uma nova.']);
            }

            $motivo = trim($motivo);
            if ($motivo === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do encerramento.']);
            }

            if ($dataFim < $actual->data_inicio->toDateString()) {
                throw ValidationException::withMessages(['data_fim' => 'A data de fim não pode ser anterior à data de início.']);
            }

            if ($dataFim < $hoje->toDateString()) {
                throw ValidationException::withMessages(['data_fim' => 'A data de fim não pode ser anterior a hoje.']);
            }

            if ($dataFim > $turma->anoLectivo->data_fim->toDateString()) {
                throw ValidationException::withMessages(['data_fim' => 'A data de fim tem de estar dentro do ano lectivo (até ' . $turma->anoLectivo->data_fim->format('d/m/Y') . ').']);
            }

            // Só se encurta: alargar a vigência poderia sobrepor-se a outra atribuição (isso faz-se em Actualizar,
            // que revalida). Com `data_fim` nula (aberta até ao fim do ano) qualquer data do ano é encurtar.
            if ($actual->data_fim !== null && $dataFim > $actual->data_fim->toDateString()) {
                throw ValidationException::withMessages(['data_fim' => 'Encerrar só encurta a vigência; para a alargar use Editar vigência.']);
            }

            $actual->update(['data_fim' => $dataFim, 'motivo_encerramento' => $motivo]);

            return $actual;
        });
    }
}
