<?php

namespace Modules\Docente\Tests\Feature;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\AtribuicaoDocenteConsultaService;
use Modules\Docente\Services\ExportacaoDeAtribuicoesService;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\Turno;
use Tests\TestCase;

class AtribuicaoDocenteConsultaServiceTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function servico(): AtribuicaoDocenteConsultaService
    {
        return app(AtribuicaoDocenteConsultaService::class);
    }

    private function criar(array $c, $disciplina, int $docenteId, PapelDocente $papel, string $inicio, ?string $fim = null): AtribuicaoDocente
    {
        return AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $disciplina->id,
            'docente_id' => $docenteId, 'papel' => $papel, 'data_inicio' => $inicio, 'data_fim' => $fim,
        ]);
    }

    private function linha(array $linhas, int $disciplinaId): array
    {
        return collect($linhas)->firstWhere('disciplina_id', $disciplinaId);
    }

    public function test_por_turma_marca_disciplinas_sem_titular(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->criar($c, $c['disciplina'], $prof->id, PapelDocente::TITULAR, '2026-01-01');

        $r = $this->servico()->porTurma($c['ano']->id, $c['turma']->id);

        $this->assertCount(2, $r['linhas']);
        $mat = $this->linha($r['linhas'], $c['disciplina']->id);
        $this->assertFalse($mat['sem_titular']);
        $this->assertFalse($mat['conta_como_em_falta']);
        $this->assertSame($prof->id, $mat['titular']['docente_id']);
        $this->assertSame('Matemática', $mat['disciplina_nome']);

        $por = $this->linha($r['linhas'], $c['outraDisciplina']->id);
        $this->assertTrue($por['sem_titular']);
        $this->assertTrue($por['conta_como_em_falta']);
        $this->assertNull($por['titular']);
        $this->assertNull($r['motivo_vazio']);
    }

    public function test_por_turma_sem_plano_devolve_motivo_vazio(): void
    {
        $c = $this->cenarioDeDocencia();
        PlanoCurricularAnoLectivo::query()->delete();

        $r = $this->servico()->porTurma($c['ano']->id, $c['turma']->id);

        $this->assertSame([], $r['linhas']);
        $this->assertStringContainsString('Esta turma ainda não tem disciplinas definidas', $r['motivo_vazio']);
    }

    public function test_opcional_sem_inscricoes_nao_conta_como_em_falta(): void
    {
        $c = $this->cenarioDeDocencia();
        PlanoCurricularDisciplina::where('disciplina_id', $c['outraDisciplina']->id)->update(['obrigatoria' => false]);

        $r = $this->servico()->porTurma($c['ano']->id, $c['turma']->id);

        $por = $this->linha($r['linhas'], $c['outraDisciplina']->id);
        $this->assertTrue($por['sem_titular']);
        $this->assertFalse($por['conta_como_em_falta']);
        $this->assertTrue($this->linha($r['linhas'], $c['disciplina']->id)['conta_como_em_falta']);
    }

    public function test_substituto_sem_titular_conta_como_em_falta(): void
    {
        $c = $this->cenarioDeDocencia();
        $sub = $this->criarDocente('Docente sub');
        $this->criar($c, $c['disciplina'], $sub->id, PapelDocente::SUBSTITUTO, '2026-01-01');

        $linha = $this->linha($this->servico()->porTurma($c['ano']->id, $c['turma']->id)['linhas'], $c['disciplina']->id);

        $this->assertTrue($linha['sem_titular']);
        $this->assertTrue($linha['conta_como_em_falta']);
        $this->assertCount(1, $linha['substitutos']);
        $this->assertSame('em_curso', $linha['substitutos'][0]['estado']);
    }

    public function test_substituto_agendado_aparece_marcado(): void
    {
        $c = $this->cenarioDeDocencia();
        $sub = $this->criarDocente('Docente sub');
        $this->criar($c, $c['disciplina'], $sub->id, PapelDocente::SUBSTITUTO, '2026-09-01');

        $linha = $this->linha($this->servico()->porTurma($c['ano']->id, $c['turma']->id)['linhas'], $c['disciplina']->id);

        $this->assertSame('agendada', $linha['substitutos'][0]['estado']);
    }

    public function test_indicadores_contam_sem_titular_em_toda_a_escola(): void
    {
        $c = $this->cenarioDeDocencia();
        $prof = $this->criarDocente();
        $this->criar($c, $c['disciplina'], $prof->id, PapelDocente::TITULAR, '2026-01-01');

        $i = $this->servico()->indicadores($c['ano']->id);
        $this->assertSame(1, $i['em_vigor']);
        $this->assertSame(1, $i['disciplinas_sem_titular']);

        $this->criar($c, $c['outraDisciplina'], $prof->id, PapelDocente::TITULAR, '2026-01-01');
        $i = $this->servico()->indicadores($c['ano']->id);
        $this->assertSame(2, $i['em_vigor']);
        $this->assertSame(0, $i['disciplinas_sem_titular']);
    }

    public function test_lista_filtra_e_pagina(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarDocente('Docente a');
        $b = $this->criarDocente('Docente b');
        $this->criar($c, $c['disciplina'], $a->id, PapelDocente::TITULAR, '2026-01-01');
        $this->criar($c, $c['outraDisciplina'], $b->id, PapelDocente::SUBSTITUTO, '2026-09-01');

        $todas = $this->servico()->lista(['ano_lectivo_id' => $c['ano']->id], 1);
        $this->assertSame(2, $todas->total());
        $this->assertCount(1, $todas->items());

        $porProf = $this->servico()->lista(['ano_lectivo_id' => $c['ano']->id, 'docente_id' => $a->id]);
        $this->assertSame(1, $porProf->total());

        $agendadas = $this->servico()->lista(['ano_lectivo_id' => $c['ano']->id, 'estado_vigencia' => 'agendada']);
        $this->assertSame(1, $agendadas->total());
        $this->assertSame('agendada', $agendadas->items()[0]['estado']);

        $porPapel = $this->servico()->lista(['ano_lectivo_id' => $c['ano']->id, 'papel' => PapelDocente::TITULAR->value]);
        $this->assertSame(1, $porPapel->total());

        $pesquisa = $this->servico()->lista(['ano_lectivo_id' => $c['ano']->id, 'pesquisa' => 'Portug']);
        $this->assertSame(1, $pesquisa->total());
    }

    public function test_opcoes_lista_so_docentes_activos_com_indicacao_de_acesso(): void
    {
        $c = $this->cenarioDeDocencia();
        $semConta = $this->criarDocente('Bruno Sem Conta');
        $comConta = $this->criarDocente('Ana Com Conta', comAcesso: true);
        $outro = $this->criarDocente('Docente inactivo');
        $outro->update(['estado' => 0]);

        $o = $this->servico()->opcoes($c['ano']->id);

        $this->assertArrayNotHasKey('professores', $o);
        $this->assertSame([
            ['id' => $comConta->id, 'nome' => 'Ana Com Conta', 'tem_acesso' => true],
            ['id' => $semConta->id, 'nome' => 'Bruno Sem Conta', 'tem_acesso' => false],
        ], $o['docentes']);
        $this->assertSame([$c['turma']->id], array_column($o['turmas'], 'id'));
        $this->assertSame([$c['ano']->id], array_column($o['anosLectivos'], 'id'));
    }

    public function test_linhas_trazem_o_nome_do_docente_e_a_pesquisa_procura_por_ele(): void
    {
        $c = $this->cenarioDeDocencia();
        $docente = $this->criarDocente('Carla Pesquisável');
        $this->criar($c, $c['disciplina'], $docente->id, PapelDocente::TITULAR, '2026-01-01');

        $mat = $this->linha($this->servico()->porTurma($c['ano']->id, $c['turma']->id)['linhas'], $c['disciplina']->id);
        $this->assertSame('Carla Pesquisável', $mat['titular']['docente_nome']);

        $lista = $this->servico()->lista(['ano_lectivo_id' => $c['ano']->id, 'pesquisa' => 'Carla']);
        $this->assertSame(1, $lista->total());
        $this->assertSame('Carla Pesquisável', $lista->items()[0]['docente_nome']);
    }

    public function test_ano_encerrado_usa_o_ultimo_dia_do_ano_como_referencia(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarDocente('Docente a');
        $b = $this->criarDocente('Docente b');
        $d = $this->criarDocente('Docente d');
        // Matemática: titular antigo terminou a meio do ano e foi substituído por outro, aberto até ao fim.
        $this->criar($c, $c['disciplina'], $a->id, PapelDocente::TITULAR, '2026-01-01', '2026-06-30');
        $novo = $this->criar($c, $c['disciplina'], $b->id, PapelDocente::TITULAR, '2026-07-01');
        // Português: titular terminou no último dia do ano.
        $this->criar($c, $c['outraDisciplina'], $d->id, PapelDocente::TITULAR, '2026-01-01', '2026-12-31');
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        Carbon::setTestNow(CarbonImmutable::parse('2027-03-10 10:00:00', 'UTC'));

        $r = $this->servico()->porTurma($c['ano']->id, $c['turma']->id);
        $mat = $this->linha($r['linhas'], $c['disciplina']->id);
        $this->assertSame($novo->id, $mat['titular']['id']);
        $this->assertFalse($mat['sem_titular']);
        $this->assertSame('em_curso', $mat['titular']['estado']);
        $this->assertFalse($this->linha($r['linhas'], $c['outraDisciplina']->id)['conta_como_em_falta']);

        $i = $this->servico()->indicadores($c['ano']->id);
        $this->assertSame(2, $i['em_vigor']);
        $this->assertSame(0, $i['disciplinas_sem_titular']);
    }

    public function test_ano_planeado_usa_o_primeiro_dia_do_ano_como_referencia(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarDocente('Docente a');
        $this->criar($c, $c['disciplina'], $a->id, PapelDocente::TITULAR, '2026-01-01');
        $c['ano']->update(['estado' => EstadoAnoLectivo::PLANEADO]);
        Carbon::setTestNow(CarbonImmutable::parse('2025-11-10 10:00:00', 'UTC'));

        $r = $this->servico()->porTurma($c['ano']->id, $c['turma']->id);
        $this->assertSame('em_curso', $this->linha($r['linhas'], $c['disciplina']->id)['titular']['estado']);
        $this->assertSame(1, $this->servico()->indicadores($c['ano']->id)['em_vigor']);
        $this->assertSame(1, $this->servico()->indicadores($c['ano']->id)['disciplinas_sem_titular']);
    }

    public function test_filtro_estado_vigencia_usa_a_mesma_referencia(): void
    {
        $c = $this->cenarioDeDocencia();
        $a = $this->criarDocente('Docente a');
        $b = $this->criarDocente('Docente b');
        $this->criar($c, $c['disciplina'], $a->id, PapelDocente::TITULAR, '2026-01-01', '2026-06-30');
        $this->criar($c, $c['disciplina'], $b->id, PapelDocente::TITULAR, '2026-07-01');
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        Carbon::setTestNow(CarbonImmutable::parse('2027-03-10 10:00:00', 'UTC'));

        $base = ['ano_lectivo_id' => $c['ano']->id];
        $emCurso = $this->servico()->lista($base + ['estado_vigencia' => 'em_curso']);
        $this->assertSame(1, $emCurso->total());
        $this->assertSame('em_curso', $emCurso->items()[0]['estado']);
        $this->assertSame(1, $this->servico()->lista($base + ['estado_vigencia' => 'terminada'])->total());
        $this->assertSame(0, $this->servico()->lista($base + ['estado_vigencia' => 'agendada'])->total());
    }

    public function test_sem_ano_activo_o_padrao_e_o_ano_mais_recente(): void
    {
        $c = $this->cenarioDeDocencia();
        $c['ano']->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $novo = AnoLectivo::create([
            'estabelecimento_id' => $c['ano']->estabelecimento_id, 'nome' => '2027', 'data_inicio' => '2027-01-01',
            'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::PLANEADO,
        ]);

        $this->assertSame($novo->id, $this->servico()->anoLectivoPadrao());

        // A exportação sem ano usa o mesmo padrão: não mistura anos.
        $tabela = app(ExportacaoDeAtribuicoesService::class)->tabela([]);
        $this->assertStringContainsString('Ano lectivo: 2027', $tabela->subtitulo);
    }

    public function test_com_ano_activo_o_padrao_e_o_activo(): void
    {
        $c = $this->cenarioDeDocencia();
        AnoLectivo::create([
            'estabelecimento_id' => $c['ano']->estabelecimento_id, 'nome' => '2027', 'data_inicio' => '2027-01-01',
            'data_fim' => '2027-12-31', 'estado' => EstadoAnoLectivo::PLANEADO,
        ]);

        $this->assertSame($c['ano']->id, $this->servico()->anoLectivoPadrao());
    }

    public function test_opcoes_trazem_as_disciplinas_com_atribuicoes_no_ano(): void
    {
        $c = $this->cenarioDeDocencia();
        $this->assertSame([], $this->servico()->opcoes($c['ano']->id)['disciplinas']);

        $this->criar($c, $c['outraDisciplina'], $this->criarDocente()->id, PapelDocente::TITULAR, '2026-03-01');

        $this->assertSame(
            [['id' => $c['outraDisciplina']->id, 'nome' => 'Português']],
            $this->servico()->opcoes($c['ano']->id)['disciplinas'],
        );
    }

    public function test_rotulo_de_turma_curto_na_lista_e_completo_nas_opcoes(): void
    {
        $c = $this->cenarioDeDocencia();
        $turno = Turno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'nome' => 'Manhã']);
        $c['turma']->update(['turno_id' => $turno->id]);
        $this->criar($c, $c['disciplina'], $this->criarDocente()->id, PapelDocente::TITULAR, '2026-03-01');

        $linha = $this->servico()->lista(['ano_lectivo_id' => $c['ano']->id])->items()[0];
        $opcao = $this->servico()->opcoes($c['ano']->id)['turmas'][0];

        $this->assertSame('Turma 10A', $linha['turma_rotulo']);
        $this->assertSame('Turma 10A · Manhã · 2026', $opcao['rotulo']);
    }
}
