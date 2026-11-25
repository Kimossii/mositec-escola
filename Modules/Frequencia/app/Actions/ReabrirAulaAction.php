<?php

namespace Modules\Frequencia\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Services\AutorizacaoDeChamadaService;
use Modules\Frequencia\Services\JanelaDeCorreccaoService;
use Modules\Usuario\Models\User;

/**
 * Desfaz o «aula não leccionada» (engano ou aula afinal dada): a aula volta a realizada e as presenças que tinha
 * voltam a contar. Mesmas regras de quem marca e da janela de correcção; o registo da operação fica no log.
 */
class ReabrirAulaAction
{
    use LogService;

    public function __construct(
        private AutorizacaoDeChamadaService $autorizacao,
        private JanelaDeCorreccaoService $janela,
    ) {
    }

    public function executar(User $utilizador, Aula $aula): Aula
    {
        return $this->comLog('Reabrir aula não leccionada', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador, $aula));
    }

    private function executarSemRegisto(User $utilizador, Aula $aula): Aula
    {
        return DB::transaction(function () use ($utilizador, $aula) {
            $actual = Aula::query()->whereKey($aula->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->autorizacao->podeMarcar($utilizador, $actual->turma_id, $actual->disciplina_id, $actual->tipo, $actual->data)) {
                throw new AuthorizationException('Não lecciona esta turma neste dia.');
            }

            if ($actual->estado !== EstadoAula::NAO_LECCIONADA) {
                throw ValidationException::withMessages(['aula' => 'A aula não está marcada como não leccionada.']);
            }

            if (! $this->janela->dentroDaJanela($actual->data) && ! $utilizador->can('frequencia.corrigir-fora-prazo')) {
                throw ValidationException::withMessages(['aula' => 'A janela de correcção desta chamada já passou. Peça à secretaria.']);
            }

            $actual->update(['estado' => EstadoAula::REALIZADA, 'motivo_nao_leccionada' => null]);

            return $actual;
        });
    }
}
