<?php

namespace Modules\Frequencia\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Frequencia\DTO\AulaPrevista;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Services\AlunosDaAulaService;
use Modules\Frequencia\Services\AulasPrevistasService;
use Modules\Frequencia\Services\AutorizacaoDeChamadaService;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;

/**
 * Abre a chamada de uma aula prevista: guarda a aula (fotografia) e cria todos os alunos da lista como presentes, para
 * o professor só marcar as excepções. Idempotente: abrir de novo devolve a mesma aula (a corrida de duas aberturas é
 * coberta pelos índices únicos). Só de hoje ou de dias passados.
 */
class AbrirChamadaAction
{
    use LogService;

    public function __construct(
        private AulasPrevistasService $previstas,
        private AlunosDaAulaService $alunos,
        private AutorizacaoDeChamadaService $autorizacao,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(User $utilizador, int $turmaId, string $data, ?int $horarioId): Aula
    {
        return $this->comLog('Abrir chamada', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($utilizador, $turmaId, $data, $horarioId));
    }

    private function executarSemRegisto(User $utilizador, int $turmaId, string $data, ?int $horarioId): Aula
    {
        if ($data > $this->relogio->hoje()->toDateString()) {
            throw ValidationException::withMessages(['data' => 'Só se abre a chamada de hoje ou de dias passados.']);
        }

        try {
            return DB::transaction(fn () => $this->abrir($utilizador, $turmaId, $data, $horarioId));
        } catch (QueryException $e) {
            // Duas aberturas ao mesmo tempo: a outra ganhou; devolve a que ficou.
            $existente = $this->existente($turmaId, $data, $horarioId);

            if ($existente === null) {
                throw $e;
            }

            return $existente;
        }
    }

    private function abrir(User $utilizador, int $turmaId, string $data, ?int $horarioId): Aula
    {
        Turma::query()->whereKey($turmaId)->lockForUpdate()->first();
        $dia = Carbon::parse($data);

        $existente = $this->existente($turmaId, $data, $horarioId);
        if ($existente !== null) {
            $this->exigirPodeMarcar($utilizador, $existente->turma_id, $existente->disciplina_id, $existente->tipo, $dia);

            return $existente;
        }

        $prevista = $this->prevista($turmaId, $data, $horarioId);
        $this->exigirPodeMarcar($utilizador, $turmaId, $prevista->disciplina_id, $prevista->tipo, $dia);

        $turma = Turma::query()->findOrFail($turmaId);
        $aula = Aula::create([
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'turma_id' => $turmaId,
            'tipo' => $prevista->tipo,
            'data' => $data,
            'horario_id' => $prevista->horario_id,
            'disciplina_id' => $prevista->disciplina_id,
            'docente_id' => $this->autorizacao->docenteDoUtilizador($utilizador) ?? $prevista->docente_id,
            'sala_id' => $prevista->sala_id,
        ]);

        foreach ($this->alunos->daAula($turmaId, $dia, $prevista->tipo, $prevista->disciplina_id) as $matricula) {
            $registo = Registo::create(['aula_id' => $aula->id, 'matricula_id' => $matricula->id, 'estado' => EstadoPresenca::PRESENTE]);
            RegistoHistorico::create([
                'registo_id' => $registo->id,
                'evento' => EventoFrequencia::MARCACAO,
                'estado_novo' => EstadoPresenca::PRESENTE,
                'alterado_por' => $utilizador->id,
            ]);
        }

        return $aula->load('registos');
    }

    private function existente(int $turmaId, string $data, ?int $horarioId): ?Aula
    {
        return Aula::query()
            ->where('turma_id', $turmaId)
            ->whereDate('data', $data)
            ->when($horarioId === null, fn ($q) => $q->whereNull('horario_id'), fn ($q) => $q->where('horario_id', $horarioId))
            ->with('registos')
            ->first();
    }

    private function prevista(int $turmaId, string $data, ?int $horarioId): AulaPrevista
    {
        foreach ($this->previstas->daTurmaNoDia($turmaId, Carbon::parse($data)) as $prevista) {
            if ($prevista->horario_id === $horarioId) {
                return $prevista;
            }
        }

        throw ValidationException::withMessages(['data' => 'Não há aula prevista para esta turma neste dia e tempo.']);
    }

    private function exigirPodeMarcar(User $utilizador, int $turmaId, ?int $disciplinaId, TipoAula $tipo, Carbon $dia): void
    {
        if (! $this->autorizacao->podeMarcar($utilizador, $turmaId, $disciplinaId, $tipo, $dia)) {
            throw new AuthorizationException('Não lecciona esta turma neste dia.');
        }
    }
}
