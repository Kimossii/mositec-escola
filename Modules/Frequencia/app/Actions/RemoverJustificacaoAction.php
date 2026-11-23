<?php

namespace Modules\Frequencia\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Services\AutorizacaoDeChamadaService;
use Modules\Usuario\Models\User;

/** Retira a justificação (com motivo): a falta volta a injustificada e fica a história de quem justificou e de quem retirou. */
class RemoverJustificacaoAction
{
    use LogService;

    public function __construct(private AutorizacaoDeChamadaService $autorizacao)
    {
    }

    public function executar(User $utilizador, Registo $registo, string $motivo): void
    {
        $this->comLog('Remover justificação de falta', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador, $registo, $motivo));
    }

    private function executarSemRegisto(User $utilizador, Registo $registo, string $motivo): void
    {
        $motivo = trim($motivo);

        DB::transaction(function () use ($utilizador, $registo, $motivo) {
            $actual = Registo::query()->whereKey($registo->getKey())->with('aula')->lockForUpdate()->firstOrFail();
            $aula = $actual->aula;

            if (! $utilizador->can('frequencia.justificar')
                || ! $this->autorizacao->podeMarcar($utilizador, $aula->turma_id, $aula->disciplina_id, $aula->tipo, $aula->data)) {
                throw new AuthorizationException('Não pode alterar justificações desta aula.');
            }

            $justificacao = $actual->justificacao;
            if ($justificacao === null) {
                throw ValidationException::withMessages(['registo' => 'Esta falta não tem justificação.']);
            }

            if ($motivo === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo de retirar a justificação.']);
            }

            $justificacao->delete();
            RegistoHistorico::create([
                'registo_id' => $actual->id,
                'evento' => EventoFrequencia::REMOCAO_JUSTIFICACAO,
                'estado_anterior' => EstadoPresenca::FALTA,
                'estado_novo' => EstadoPresenca::FALTA,
                'motivo' => $motivo,
                'alterado_por' => $utilizador->id,
            ]);
        });
    }
}
