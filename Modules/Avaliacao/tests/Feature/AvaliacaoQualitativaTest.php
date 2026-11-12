<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\LancarQualitativaAction;
use Modules\Avaliacao\DTO\LancarQualitativaDTO;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoPautaLinhaQualitativa;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class AvaliacaoQualitativaTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private AvaliacaoEscala $escala;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->c, $this->escala] = $this->cenarioQualitativo();
        $this->inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->travelTo('2026-03-15 10:00:00');
    }

    private function nivel(string $codigo): int
    {
        return $this->escala->niveis->firstWhere('codigo', $codigo)->id;
    }

    private function lancar(int $trimestre, string $codigo, ?string $observacao = null, ?string $motivo = null, ?InscricaoDisciplina $inscricao = null): AvaliacaoQualitativa
    {
        return app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO(($inscricao ?? $this->inscricao)->id, $this->c['periodos'][$trimestre]->id, $this->nivel($codigo), $observacao, $motivo));
    }

    private function admin(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
    }

    private function pauta(): array
    {
        return app(PautaFinalService::class)->paraTurma($this->c['turma']->id);
    }

    public function test_docente_atribuido_lanca_e_a_alteracao_fica_no_historico(): void
    {
        $this->actingAs($this->professorAtribuido($this->c));

        $a = $this->lancar(1, 'B', 'Participa bem');
        $this->assertSame('Participa bem', $a->observacao);

        $this->lancar(1, 'MB', 'Participa bem');
        $this->lancar(1, 'MB', 'Participa bem');

        $h = $a->historico()->orderBy('id')->get();
        $this->assertCount(2, $h);
        $this->assertNull($h[0]->escala_nivel_anterior_id);
        $this->assertSame($this->nivel('B'), $h[1]->escala_nivel_anterior_id);
        $this->assertSame($this->nivel('MB'), $h[1]->escala_nivel_novo_id);
        $this->assertSame(1, AvaliacaoQualitativa::count());
    }

    public function test_quem_nao_lecciona_a_area_nao_lanca(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com'));

        $this->expectException(AuthorizationException::class);
        $this->lancar(1, 'B');
    }

    public function test_depois_do_fim_do_trimestre_so_com_permissao_e_motivo(): void
    {
        $this->travelTo('2026-05-10 10:00:00');
        $this->actingAs($this->professorAtribuido($this->c));

        try {
            $this->lancar(1, 'B');
            $this->fail('O professor não lança depois do prazo.');
        } catch (AuthorizationException) {
        }

        $this->admin();
        try {
            $this->lancar(1, 'B');
            $this->fail('Esperava ValidationException (motivo).');
        } catch (ValidationException) {
        }

        $this->lancar(1, 'B', null, 'Avaliação entregue em atraso');
        $this->assertTrue(AvaliacaoQualitativa::firstOrFail()->historico()->firstOrFail()->fora_do_prazo);
    }

    public function test_nivel_de_outra_escala_e_rejeitado(): void
    {
        $this->admin();
        $outra = $this->guardarEscala(null, 'Outra', [['codigo' => 'X', 'designacao' => 'Xis'], ['codigo' => 'Y', 'designacao' => 'Ipsilon']]);

        $this->expectException(ValidationException::class);
        app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($this->inscricao->id, $this->c['periodos'][1]->id, $outra->niveis[0]->id));
    }

    public function test_sem_escala_escolhida_ou_com_trimestre_de_outro_ano_e_rejeitado(): void
    {
        $this->admin();
        $outro = $this->cenarioDeAvaliacao('PRI_3', 'Z');

        try {
            app(LancarQualitativaAction::class)->executar(new LancarQualitativaDTO($this->inscricao->id, $outro['periodos'][1]->id, $this->nivel('B')));
            $this->fail('Trimestre de outro ano.');
        } catch (ValidationException) {
        }

        AvaliacaoRegraQualitativa::query()->delete();
        $this->expectException(ValidationException::class);
        $this->lancar(1, 'B');
    }

    public function test_mfd_e_o_nivel_do_ultimo_trimestre_sem_calcular_medias(): void
    {
        $this->admin();
        $this->lancar(1, 'MB');
        $this->lancar(2, 'SUF');
        $this->lancar(3, 'B');

        $p = $this->pauta();
        $celula = $p['alunos'][0]['celulas'][$this->c['disciplina']->id];

        $this->assertSame('qualitativa', $p['tipo']);
        $this->assertSame(['Muito Bom', 'Suficiente', 'Bom', 'Bom'], [$celula['mt1'], $celula['mt2'], $celula['mt3'], $celula['mfd']]);
        $this->assertTrue($p['pode_aprovar']);
        $this->assertSame('Transita', $p['alunos'][0]['decisao']['rotulo']);
    }

    public function test_sem_o_3_trimestre_ha_pendente_e_nao_se_aprova(): void
    {
        $this->admin();
        $this->lancar(1, 'B');

        $p = $this->pauta();

        $this->assertFalse($p['pode_aprovar']);
        $this->assertStringContainsString('3.º trimestre', $p['bloqueios'][0]);
        $this->assertNull($p['alunos'][0]['decisao']);
        $this->assertNull($p['alunos'][0]['celulas'][$this->c['disciplina']->id]['mfd']);
    }

    public function test_aprovar_grava_o_instantaneo_e_a_escala_pode_mudar_depois(): void
    {
        $this->admin();
        $this->lancar(1, 'B');
        $this->lancar(2, 'B');
        $this->lancar(3, 'MB');

        $this->assertSame('definitiva', $this->aprovar()['estado']);
        $linha = AvaliacaoPautaLinhaQualitativa::firstOrFail();
        $this->assertSame(['codigo' => 'MB', 'designacao' => 'Muito Bom'], $linha->mfd);

        $this->escala->niveis->firstWhere('codigo', 'MB')->update(['designacao' => 'Excelente']);

        $p = $this->pauta();
        $this->assertSame('Muito Bom', $p['alunos'][0]['celulas'][$this->c['disciplina']->id]['mfd']);
        $this->assertSame('Transita', $p['alunos'][0]['decisao']['rotulo']);

        $this->expectException(ValidationException::class);
        $this->lancar(3, 'B');
    }

    private function aprovar(): array
    {
        app(AprovarPautaAction::class)->executar($this->c['turma']->id);

        return $this->pauta();
    }
}
