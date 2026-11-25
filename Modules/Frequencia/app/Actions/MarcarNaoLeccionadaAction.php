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

/** Marca a aula como não leccionada (com motivo): deixa de contar para faltas e para «chamadas por fazer». */
class MarcarNaoLeccionadaAction
{
    use LogService;

    public function __construct(
        private AutorizacaoDeChamadaService $autorizacao,
        private JanelaDeCorreccaoService $janela,
    ) {
    }

    public function executar(User $utilizador, Aula $aula, string $motivo): Aula
    {
        return $this->comLog('Marcar aula não leccionada', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador, $aula, $motivo));
    }

    private function executarSemRegisto(User $utilizador, Aula $aula, string $motivo): Aula
    {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique o motivo de a aula não ter sido leccionada.']);
        }

        return DB::transaction(function () use ($utilizador, $aula, $motivo) {
            $actual = Aula::query()->whereKey($aula->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->autorizacao->podeMarcar($utilizador, $actual->turma_id, $actual->disciplina_id, $actual->tipo, $actual->data)) {
                throw new AuthorizationException('Não lecciona esta turma neste dia.');
            }

            if (! $this->janela->dentroDaJanela($actual->data) && ! $utilizador->can('frequencia.corrigir-fora-prazo')) {
                throw ValidationException::withMessages(['aula' => 'A janela de correcção desta chamada já passou. Peça à secretaria.']);
            }

            $actual->update(['estado' => EstadoAula::NAO_LECCIONADA, 'motivo_nao_leccionada' => $motivo]);

            return $actual;
        });
    }
}
