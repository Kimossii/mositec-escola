<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Services\AulasPrevistasService;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class AulasPrevistasServiceTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private function servico(): AulasPrevistasService
    {
        return app(AulasPrevistasService::class);
    }

    private function dia(array $c, string $data): array
    {
        return $this->servico()->daTurmaNoDia($c['turma']->id, Carbon::parse($data));
    }

    public function test_regime_tempo_devolve_uma_por_entrada_em_vigor(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->criarEntrada($c, ['horario_id' => $c['tempo2']->id, 'disciplina_id' => $c['outraDisciplina']->id, 'sala_id' => $c['sala']->id, 'vigencia_inicio' => '2026-01-01']);

        $previstas = $this->dia($c, '2026-03-02'); // segunda-feira

        $this->assertCount(2, $previstas);
        $this->assertSame([$c['tempo1']->id, $c['tempo2']->id], array_map(fn ($p) => $p->horario_id, $previstas));
        $this->assertSame(TipoAula::TEMPO, $previstas[0]->tipo);
        $this->assertSame($c['disciplina']->id, $previstas[0]->disciplina_id);
        $this->assertSame($c['docente']->id, $previstas[0]->docente_id);
        $this->assertSame($c['sala']->id, $previstas[1]->sala_id);
        $this->assertFalse($previstas[0]->sem_docente);
        $this->assertSame([], $this->dia($c, '2026-03-03')); // terça: sem entradas
    }

    public function test_regime_dia_devolve_uma_so_se_houver_entrada_nesse_dia_da_semana(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->criarEntrada($c, ['horario_id' => $c['tempo2']->id, 'vigencia_inicio' => '2026-01-01']);
        $c['turma']->nivelAcademico->update(['etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);

        $previstas = $this->dia($c, '2026-03-02');

        $this->assertCount(1, $previstas);
        $this->assertSame(TipoAula::DIA, $previstas[0]->tipo);
        $this->assertNull($previstas[0]->horario_id);
        $this->assertNull($previstas[0]->disciplina_id);
        $this->assertSame($c['docente']->id, $previstas[0]->docente_id);
        $this->assertSame([], $this->dia($c, '2026-03-03'));
    }

    public function test_feriado_e_ferias_anulam_o_dia(): void
    {
        $c = $this->cenarioDeFrequencia();
        EventoCalendario::create(['ano_lectivo_id' => $c['ano']->id, 'titulo' => 'Feriado', 'tipo' => TipoEventoCalendario::FERIADO, 'data_inicio' => '2026-03-02', 'data_fim' => '2026-03-02', 'dia_inteiro' => true]);
        EventoCalendario::create(['ano_lectivo_id' => $c['ano']->id, 'titulo' => 'Férias', 'tipo' => TipoEventoCalendario::FERIAS, 'data_inicio' => '2026-03-09', 'data_fim' => '2026-03-15', 'dia_inteiro' => true]);
        EventoCalendario::create(['ano_lectivo_id' => $c['ano']->id, 'titulo' => 'Reunião', 'tipo' => TipoEventoCalendario::REUNIAO, 'data_inicio' => '2026-03-16', 'data_fim' => '2026-03-16', 'dia_inteiro' => true]);

        $this->assertSame([], $this->dia($c, '2026-03-02'));
        $this->assertSame([], $this->dia($c, '2026-03-09'));
        $this->assertCount(1, $this->dia($c, '2026-03-16')); // uma reunião não anula a aula
    }

    public function test_domingo_e_fora_do_ano_nao_tem_aula(): void
    {
        $c = $this->cenarioDeFrequencia();

        $this->assertSame([], $this->dia($c, '2026-03-01')); // domingo
        $this->assertSame([], $this->dia($c, '2027-01-04')); // segunda, depois do ano
        $this->assertSame([], $this->dia($c, '2025-12-29')); // segunda, antes do ano
    }

    public function test_entrada_sem_titular_vem_sem_docente(): void
    {
        $c = $this->cenarioDeFrequencia();
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->delete();

        $p = $this->dia($c, '2026-03-02')[0];

        $this->assertNull($p->docente_id);
        $this->assertTrue($p->sem_docente);
    }

    public function test_versao_antiga_da_grelha_vale_nas_datas_antigas(): void
    {
        $c = $this->cenarioDeFrequencia();
        $c['entrada']->update(['vigencia_fim' => '2026-03-08']);
        $this->criarEntrada($c, ['disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-03-09']);

        $this->assertSame($c['disciplina']->id, $this->dia($c, '2026-03-02')[0]->disciplina_id);
        $this->assertSame($c['outraDisciplina']->id, $this->dia($c, '2026-03-09')[0]->disciplina_id);
    }

    public function test_dos_docentes_filtra_por_pares_e_no_regime_dia_por_turma(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->criarEntrada($c, ['dia_semana' => 2, 'disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-01-01']);
        $de = Carbon::parse('2026-03-02');
        $ate = Carbon::parse('2026-03-03');

        $soMatematica = $this->servico()->dosDocentes([['turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id]], $de, $ate);
        $this->assertCount(1, $soMatematica);
        $this->assertSame('2026-03-02', $soMatematica[0]->data);

        $c['turma']->nivelAcademico->update(['etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        $dias = $this->servico()->dosDocentes([['turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id]], $de, $ate);
        $this->assertSame(['2026-03-02', '2026-03-03'], array_map(fn ($p) => $p->data, $dias));
        $this->assertSame([], $this->servico()->dosDocentes([], $de, $ate));
    }

    public function test_regime_dia_usa_o_titular_de_qualquer_disciplina_do_dia(): void
    {
        $c = $this->cenarioDeFrequencia();
        // O 1.º tempo (Matemática) fica sem titular, mas a professora da turma tem Português no 2.º.
        $this->criarEntrada($c, ['horario_id' => $c['tempo2']->id, 'disciplina_id' => $c['outraDisciplina']->id, 'vigencia_inicio' => '2026-01-01']);
        AtribuicaoDocente::query()->where('disciplina_id', $c['disciplina']->id)->delete();
        $c['turma']->nivelAcademico->update(['etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);

        $p = $this->dia($c, '2026-03-02')[0];

        $this->assertSame($c['docente']->id, $p->docente_id);
        $this->assertFalse($p->sem_docente);
    }

    public function test_o_numero_de_queries_nao_cresce_com_os_dias(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->criarEntrada($c, ['dia_semana' => 2, 'vigencia_inicio' => '2026-01-01']);
        $contar = function (string $ate) use ($c) {
            $n = 0;
            \Illuminate\Support\Facades\DB::listen(function () use (&$n) {
                $n++;
            });
            $this->servico()->dasTurmas([$c['turma']->id], Carbon::parse('2026-03-02'), Carbon::parse($ate));

            return $n;
        };

        $umaSemana = $contar('2026-03-08');
        $quatroSemanas = $contar('2026-03-29');

        $this->assertSame($umaSemana, $quatroSemanas);
        $this->assertLessThan(40, $umaSemana);
    }
}
