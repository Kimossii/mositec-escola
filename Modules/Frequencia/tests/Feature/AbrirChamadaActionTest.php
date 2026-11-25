<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Frequencia\Actions\AbrirChamadaAction;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class AbrirChamadaActionTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-02 10:00:00'); // segunda-feira
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function abrir($utilizador, array $c, string $data = '2026-03-02', ?int $horarioId = -1): Aula
    {
        return app(AbrirChamadaAction::class)->executar($utilizador, $c['turma']->id, $data, $horarioId === -1 ? $c['tempo1']->id : $horarioId);
    }

    private function titular(array $c)
    {
        return $this->utilizadorDoDocente($c['docente']);
    }

    private function assertErroEm(string $campo, callable $fn): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());

            return;
        }

        $this->fail("Esperava erro de validação em «{$campo}».");
    }

    public function test_abre_e_cria_todos_como_presentes_com_historico(): void
    {
        $c = $this->cenarioDeFrequencia();
        $c['entrada']->update(['sala_id' => $c['sala']->id]);

        $aula = $this->abrir($this->titular($c), $c);

        $this->assertSame(TipoAula::TEMPO, $aula->tipo);
        $this->assertSame($c['disciplina']->id, (int) $aula->disciplina_id);
        $this->assertSame($c['tempo1']->id, (int) $aula->horario_id);
        $this->assertSame($c['sala']->id, (int) $aula->sala_id);
        $this->assertSame($c['docente']->id, (int) $aula->docente_id);
        $this->assertSame($c['ano']->id, (int) $aula->ano_lectivo_id);
        $this->assertCount(3, $aula->registos);
        $this->assertSame([EstadoPresenca::PRESENTE], $aula->registos->pluck('estado')->unique()->all());
        $this->assertSame(3, RegistoHistorico::where('evento', EventoFrequencia::MARCACAO->value)->count());
    }

    public function test_data_futura_recusada(): void
    {
        $c = $this->cenarioDeFrequencia();

        $this->assertErroEm('data', fn () => $this->abrir($this->titular($c), $c, '2026-03-09'));
        $this->assertSame(0, Aula::count());
    }

    public function test_dia_ou_tempo_sem_aula_prevista_recusado(): void
    {
        $c = $this->cenarioDeFrequencia();
        $u = $this->titular($c);

        $this->assertErroEm('data', fn () => $this->abrir($u, $c, '2026-02-24')); // terça
        $this->assertErroEm('data', fn () => $this->abrir($u, $c, '2026-03-02', $c['tempo2']->id)); // segunda, outro tempo
    }

    public function test_feriado_recusado(): void
    {
        $c = $this->cenarioDeFrequencia();
        EventoCalendario::create(['ano_lectivo_id' => $c['ano']->id, 'titulo' => 'Feriado', 'tipo' => TipoEventoCalendario::FERIADO, 'data_inicio' => '2026-03-02', 'data_fim' => '2026-03-02', 'dia_inteiro' => true]);

        $this->assertErroEm('data', fn () => $this->abrir($this->titular($c), $c));
    }

    public function test_segunda_abertura_devolve_a_mesma_aula_sem_duplicar(): void
    {
        $c = $this->cenarioDeFrequencia();
        $u = $this->titular($c);

        $primeira = $this->abrir($u, $c);
        $segunda = $this->abrir($u, $c);

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, Aula::count());
        $this->assertSame(3, Registo::count());
    }

    public function test_professor_sem_atribuicao_recusado(): void
    {
        $c = $this->cenarioDeFrequencia();
        $intruso = $this->criarDocente('Intruso', comAcesso: true)->utilizador();

        $this->expectException(AuthorizationException::class);

        $this->abrir($intruso, $c);
    }

    public function test_substituto_abre_e_fica_como_docente_da_aula(): void
    {
        $c = $this->cenarioDeFrequencia();
        $subst = $this->criarDocente('Substituto', comAcesso: true);
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $subst->id, 'papel' => PapelDocente::SUBSTITUTO, 'data_inicio' => '2026-01-01',
        ]);

        $aula = $this->abrir($subst->utilizador(), $c);

        $this->assertSame($subst->id, (int) $aula->docente_id);
    }

    public function test_regime_dia_qualquer_docente_da_turma_abre(): void
    {
        $c = $this->cenarioDeFrequencia();
        $c['turma']->nivelAcademico->update(['etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        $this->matricularAluno($c, 'Dora Só Português', '2026-02-01', $c['outraDisciplina']->id);
        $outro = $this->criarDocente('Só Português', comAcesso: true);
        $this->atribuirTitular($c, $outro, $c['outraDisciplina']->id);

        $aula = $this->abrir($outro->utilizador(), $c, '2026-03-02', null);

        $this->assertSame(TipoAula::DIA, $aula->tipo);
        $this->assertNull($aula->horario_id);
        $this->assertNull($aula->disciplina_id);
        $this->assertCount(4, $aula->registos); // os 3 de Matemática + a da Dora
        $this->assertSame($outro->id, (int) $aula->docente_id);
    }

    public function test_quem_tem_corrigir_fora_do_prazo_abre_com_o_titular_da_disciplina(): void
    {
        $c = $this->cenarioDeFrequencia();

        $aula = $this->abrir($this->funcionario(), $c);

        $this->assertSame($c['docente']->id, (int) $aula->docente_id);
    }
}
