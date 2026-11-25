<?php

namespace Modules\Frequencia\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Frequencia\DTO\PresencaDTO;
use Modules\Frequencia\Enums\EstadoAula;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Services\AutorizacaoDeChamadaService;
use Modules\Frequencia\Services\JanelaDeCorreccaoService;
use Modules\Usuario\Models\User;

/**
 * Grava as presenças de uma chamada já aberta. Fora da janela de correcção só quem tem `corrigir-fora-prazo`, e com
 * motivo. Só o que mudou vai para o histórico.
 */
class RegistarPresencasAction
{
    use LogService;

    public function __construct(
        private AutorizacaoDeChamadaService $autorizacao,
        private JanelaDeCorreccaoService $janela,
    ) {
    }

    /** @param list<PresencaDTO> $presencas */
    public function executar(User $utilizador, Aula $aula, array $presencas, ?string $motivo = null): Aula
    {
        return $this->comLog('Registar presenças', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador, $aula, $presencas, $motivo));
    }

    private function executarSemRegisto(User $utilizador, Aula $aula, array $presencas, ?string $motivo): Aula
    {
        return DB::transaction(function () use ($utilizador, $aula, $presencas, $motivo) {
            $actual = Aula::query()->whereKey($aula->getKey())->lockForUpdate()->firstOrFail();

            if ($actual->estado === EstadoAula::NAO_LECCIONADA) {
                throw ValidationException::withMessages(['aula' => 'A aula está marcada como não leccionada; não tem presenças.']);
            }

            $motivo = $this->exigirPermissaoEJanela($utilizador, $actual, $motivo);
            $registos = $actual->registos()->get()->keyBy('matricula_id');

            foreach ($presencas as $i => $p) {
                $estado = EstadoPresenca::tryFrom($p->estado);
                $registo = $registos[$p->matricula_id] ?? null;

                if ($estado === null || $registo === null) {
                    throw ValidationException::withMessages(['presencas' => "A presença n.º " . ($i + 1) . ' é inválida: aluno fora da lista desta aula ou estado desconhecido.']);
                }

                $observacao = $p->observacao === null || trim($p->observacao) === '' ? null : trim($p->observacao);

                if ($registo->estado === $estado && $registo->observacao === $observacao) {
                    continue;
                }

                $anterior = $registo->estado;
                $registo->update(['estado' => $estado, 'observacao' => $observacao]);

                // Só se justificam faltas: deixando de o ser, a justificação sai (e fica a história).
                if ($anterior === EstadoPresenca::FALTA && $estado !== EstadoPresenca::FALTA && $registo->justificacao()->exists()) {
                    $registo->justificacao()->delete();
                    RegistoHistorico::create([
                        'registo_id' => $registo->id,
                        'evento' => EventoFrequencia::REMOCAO_JUSTIFICACAO,
                        'estado_anterior' => $anterior,
                        'estado_novo' => $estado,
                        'motivo' => 'Justificação retirada: o estado passou a ' . $estado->label() . '.',
                        'alterado_por' => $utilizador->id,
                    ]);
                }

                RegistoHistorico::create([
                    'registo_id' => $registo->id,
                    'evento' => EventoFrequencia::ALTERACAO,
                    'estado_anterior' => $anterior,
                    'estado_novo' => $estado,
                    'motivo' => $motivo,
                    'alterado_por' => $utilizador->id,
                ]);
            }

            return $actual->load('registos');
        });
    }

    /** Devolve o motivo (aparado) quando a correcção é fora da janela, senão o que veio. */
    private function exigirPermissaoEJanela(User $utilizador, Aula $aula, ?string $motivo): ?string
    {
        if (! $this->autorizacao->podeMarcar($utilizador, $aula->turma_id, $aula->disciplina_id, $aula->tipo, $aula->data)) {
            throw new AuthorizationException('Não lecciona esta turma neste dia.');
        }

        if ($this->janela->dentroDaJanela($aula->data)) {
            return $motivo === null ? null : trim($motivo);
        }

        if (! $utilizador->can('frequencia.corrigir-fora-prazo')) {
            throw ValidationException::withMessages(['aula' => 'A janela de correcção desta chamada (' . $this->janela->dias() . ' dias) já passou. Peça à secretaria.']);
        }

        if ($motivo === null || trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique o motivo da correcção fora do prazo.']);
        }

        return trim($motivo);
    }
}
