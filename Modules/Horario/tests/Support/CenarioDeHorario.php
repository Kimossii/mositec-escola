<?php

namespace Modules\Horario\Tests\Support;

use Modules\Core\Enums\TipoHorarioEnum;
use Modules\Core\Models\Horario;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\Turno;
use Modules\Turma\Models\TurnoHorario;

/**
 * Cenário base da grelha: o da docência (ano 2026 activo, turma, duas disciplinas do plano) mais um turno ligado à
 * turma com dois tempos e um intervalo, uma sala e um docente titular das duas disciplinas desde 2026-01-01.
 * Requer o contexto de tenant de `Tests\Concerns\ComTenantDeTeste`.
 */
trait CenarioDeHorario
{
    use CenarioDeDocencia;

    /**
     * @return array{ano: \Modules\AnoLectivo\Models\AnoLectivo, turma: \Modules\Turma\Models\Turma, disciplina: \Modules\Disciplina\Models\Disciplina, outraDisciplina: \Modules\Disciplina\Models\Disciplina, plano: \Modules\PlanoCurricular\Models\PlanoCurricular, turno: Turno, tempo1: Horario, tempo2: Horario, intervalo: Horario, sala: Sala, docente: \Modules\Docente\Models\Docente}
     */
    protected function cenarioDeHorario(): array
    {
        $c = $this->cenarioDeDocencia();
        $estabelecimentoId = Estabelecimento::current()->id;

        $turno = Turno::create(['estabelecimento_id' => $estabelecimentoId, 'nome' => 'Manhã']);
        $c['turma']->update(['turno_id' => $turno->id]);

        $tempo1 = $this->criarTempo($turno, '1º tempo', '07:00', '07:45', 1);
        $tempo2 = $this->criarTempo($turno, '2º tempo', '07:45', '08:30', 2);
        $intervalo = $this->criarTempo($turno, 'Intervalo', '08:30', '08:45', 3, TipoHorarioEnum::PERIODO);

        $sala = Sala::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'S1', 'nome' => 'Sala 1', 'tipo' => TipoSala::SALA_AULA]);

        $docente = $this->criarDocente('Docente Titular');
        foreach ([$c['disciplina'], $c['outraDisciplina']] as $disciplina) {
            $this->atribuirTitular($c, $docente, $disciplina->id);
        }

        return $c + compact('turno', 'tempo1', 'tempo2', 'intervalo', 'sala', 'docente');
    }

    protected function criarTempo(Turno $turno, string $nome, string $inicio, string $fim, int $ordem, TipoHorarioEnum $tipo = TipoHorarioEnum::TEMPO): Horario
    {
        $horario = Horario::create(['nome' => $nome, 'hora_inicio' => $inicio, 'hora_fim' => $fim, 'tipo' => $tipo]);
        TurnoHorario::create(['turno_id' => $turno->id, 'horario_id' => $horario->id, 'ordem' => $ordem]);

        return $horario;
    }

    protected function atribuirTitular(array $c, $docente, int $disciplinaId, string $inicio = '2026-01-01', ?string $fim = null): AtribuicaoDocente
    {
        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $disciplinaId,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => $inicio, 'data_fim' => $fim,
        ]);
    }

    protected function criarEntrada(array $c, array $atributos = []): GrelhaEntrada
    {
        return GrelhaEntrada::create(array_merge([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'dia_semana' => 1,
            'horario_id' => $c['tempo1']->id, 'disciplina_id' => $c['disciplina']->id, 'sala_id' => null,
            'vigencia_inicio' => '2026-02-01', 'vigencia_fim' => null,
        ], $atributos));
    }
}
