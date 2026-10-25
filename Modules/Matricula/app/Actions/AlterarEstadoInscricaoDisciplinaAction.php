<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Core\Traits\LogService;

class AlterarEstadoInscricaoDisciplinaAction
{
    use LogService;

    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(
        InscricaoDisciplina $inscricao,
        EstadoInscricaoDisciplinaEnum $novoEstado,
        ?int $utilizadorId = null,
    ): InscricaoDisciplina
    {
        return $this->comLog('Alterar estado inscrição disciplina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($inscricao, $novoEstado, $utilizadorId));
    }

    private function executarSemRegisto(
        InscricaoDisciplina $inscricao,
        EstadoInscricaoDisciplinaEnum $novoEstado,
        ?int $utilizadorId = null,
    ): InscricaoDisciplina {
        $estadoActual = $inscricao->estado;

        if (! $estadoActual->podeTransitarPara($novoEstado)) {
            throw ValidationException::withMessages([
                'estado' => "Não é possível alterar o estado de {$estadoActual->label()} para {$novoEstado->label()}.",
            ]);
        }

        $inscricao->estado = $novoEstado;
        $inscricao->editado_por = $utilizadorId;

        if ($novoEstado->eTerminal()) {
            $inscricao->data_conclusao = $this->relogio->hoje()->toDateString();
        }

        $inscricao->save();

        return $inscricao->fresh();
    }
}
