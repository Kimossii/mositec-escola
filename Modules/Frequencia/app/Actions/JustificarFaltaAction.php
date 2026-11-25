<?php

namespace Modules\Frequencia\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Models\Justificacao;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Services\AutorizacaoDeChamadaService;
use Modules\Usuario\Models\User;

/** Justifica uma falta (nunca um atraso ou uma presença). O registo original não muda: a justificação é à parte. */
class JustificarFaltaAction
{
    use LogService;

    public function __construct(
        private AutorizacaoDeChamadaService $autorizacao,
        private RelogioDoTenant $relogio,
        private RegistarAuditoriaTenantAction $auditoria,
    ) {
    }

    public function executar(User $utilizador, Registo $registo, string $motivo, ?string $documentoReferencia): Justificacao
    {
        return $this->comLog('Justificar falta', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador, $registo, $motivo, $documentoReferencia));
    }

    private function executarSemRegisto(User $utilizador, Registo $registo, string $motivo, ?string $documentoReferencia): Justificacao
    {
        $motivo = trim($motivo);

        return DB::transaction(function () use ($utilizador, $registo, $motivo, $documentoReferencia) {
            $actual = Registo::query()->whereKey($registo->getKey())->with('aula')->lockForUpdate()->firstOrFail();
            $this->autorizar($utilizador, $actual);

            if ($actual->estado !== EstadoPresenca::FALTA) {
                throw ValidationException::withMessages(['registo' => 'Só se justificam faltas.']);
            }

            if ($actual->justificacao()->exists()) {
                throw ValidationException::withMessages(['registo' => 'Esta falta já está justificada.']);
            }

            if ($motivo === '') {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo da justificação.']);
            }

            $referencia = $documentoReferencia === null || trim($documentoReferencia) === '' ? null : trim($documentoReferencia);
            $justificacao = Justificacao::create([
                'registo_id' => $actual->id,
                'motivo' => $motivo,
                'documento_referencia' => $referencia,
                'justificada_por' => $utilizador->id,
                'justificada_em' => $this->relogio->agora(),
            ]);

            RegistoHistorico::create([
                'registo_id' => $actual->id,
                'evento' => EventoFrequencia::JUSTIFICACAO,
                'estado_anterior' => EstadoPresenca::FALTA,
                'estado_novo' => EstadoPresenca::FALTA,
                'motivo' => $motivo,
                'alterado_por' => $utilizador->id,
            ]);

            $this->auditoria->executar(new AuditoriaTenantDTO(
                'falta.justificada', 'frequencia_registos', (int) $actual->id,
                ['motivo' => null, 'documento_referencia' => null],
                ['motivo' => $motivo, 'documento_referencia' => $referencia],
                (int) $utilizador->id,
            ));

            return $justificacao;
        });
    }

    /** Quem tem `frequencia.justificar`; o professor só nas aulas em que pode marcar a chamada. */
    private function autorizar(User $utilizador, Registo $registo): void
    {
        $aula = $registo->aula;

        if (! $utilizador->can('frequencia.justificar')
            || ! $this->autorizacao->podeMarcar($utilizador, $aula->turma_id, $aula->disciplina_id, $aula->tipo, $aula->data)) {
            throw new AuthorizationException('Não pode justificar faltas desta aula.');
        }
    }
}
