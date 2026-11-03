<?php

namespace Modules\Avaliacao\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\DefinirPrazoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\Actions\ProlongarPrazoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Services\PrazoService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

class PrazoDeAvaliacaoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private AvaliacaoRegra $regra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
        $this->regra = app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO(
            ano_lectivo_id: $this->c['ano']->id, nivel_academico_id: $this->c['nivel']->id, escala_maxima: 10, nota_minima: '5',
        ));
    }

    private function limite(?int $docenteId = null): string
    {
        return app(PrazoService::class)->dataLimite(
            $this->regra, $this->c['periodos'][1]->id, TipoInstrumento::MAC, $this->c['turma']->id, $this->c['disciplina']->id, $docenteId,
        )->toDateString();
    }

    private function prazo(string $data = '2026-04-10'): AvaliacaoPrazo
    {
        return app(DefinirPrazoAction::class)->executar($this->regra->id, $this->c['periodos'][1]->id, TipoInstrumento::MAC, $data);
    }

    public function test_sem_prazo_nem_excepcao_vale_o_fim_do_periodo(): void
    {
        $this->assertSame('2026-04-30', $this->limite());
    }

    public function test_prazo_da_regra_vence_o_fim_do_periodo(): void
    {
        $this->prazo('2026-04-10');

        $this->assertSame('2026-04-10', $this->limite());
    }

    public function test_excepcao_por_turma_vence_o_prazo_da_regra(): void
    {
        $prazo = $this->prazo('2026-04-10');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        app(ProlongarPrazoAction::class)->executar($prazo->id, '2026-04-20', 'Greve', turmaId: $this->c['turma']->id);

        $this->assertSame('2026-04-20', $this->limite());
    }

    public function test_excepcao_de_outra_turma_nao_se_aplica(): void
    {
        $prazo = $this->prazo('2026-04-10');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $outraTurma = Turma::create([
            'ano_lectivo_id' => $this->c['ano']->id, 'nivel_academico_id' => $this->c['nivel']->id,
            'curso_id' => $this->c['turma']->curso_id, 'codigo' => 'TUB', 'nome' => 'TurmaB',
        ]);

        app(ProlongarPrazoAction::class)->executar($prazo->id, '2026-04-20', 'Greve', turmaId: $outraTurma->id);

        $this->assertSame('2026-04-10', $this->limite());
    }

    public function test_excepcao_por_docente_mais_especifica_vence_a_de_turma(): void
    {
        $prazo = $this->prazo('2026-04-10');
        $docente = $this->criarDocenteDeAvaliacao();
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $acao = app(ProlongarPrazoAction::class);
        $acao->executar($prazo->id, '2026-04-25', 'Turma', turmaId: $this->c['turma']->id);
        $acao->executar($prazo->id, '2026-04-15', 'Doença', docenteId: $docente->id);

        $this->assertSame('2026-04-15', $this->limite($docente->id));
        $this->assertSame('2026-04-25', $this->limite());
    }

    public function test_prazo_no_proprio_dia_ainda_nao_esta_encerrado(): void
    {
        $this->prazo('2026-04-10');
        $servico = app(PrazoService::class);
        $args = [$this->regra, $this->c['periodos'][1]->id, TipoInstrumento::MAC, $this->c['turma']->id, $this->c['disciplina']->id];

        $this->assertFalse($servico->prazoEncerrado(...$args, docenteId: null, hoje: CarbonImmutable::parse('2026-04-10 23:59')));
        $this->assertTrue($servico->prazoEncerrado(...$args, docenteId: null, hoje: CarbonImmutable::parse('2026-04-11 00:01')));
    }

    public function test_prolongar_exige_permissao(): void
    {
        $prazo = $this->prazo();
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'prof@example.com'));

        $this->expectException(AuthorizationException::class);
        app(ProlongarPrazoAction::class)->executar($prazo->id, '2026-04-20', 'Motivo');
    }

    public function test_prolongar_exige_motivo_e_data_posterior(): void
    {
        $prazo = $this->prazo('2026-04-10');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));
        $acao = app(ProlongarPrazoAction::class);

        try {
            $acao->executar($prazo->id, '2026-04-20', '  ');
            $this->fail('Esperava ValidationException (motivo).');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $acao->executar($prazo->id, '2026-04-05', 'Motivo');
    }

    public function test_prazo_duplicado_para_regra_periodo_tipo_e_rejeitado_pela_bd(): void
    {
        $this->prazo();

        $this->expectException(QueryException::class);
        AvaliacaoPrazo::create(['regra_id' => $this->regra->id, 'periodo_id' => $this->c['periodos'][1]->id, 'tipo' => TipoInstrumento::MAC, 'data_limite' => '2026-04-12']);
    }

    public function test_definir_prazo_de_periodo_de_outro_ano_e_rejeitado(): void
    {
        $outro = $this->cenarioDeAvaliacao('PRI_4', 'Z');

        $this->expectException(ValidationException::class);
        app(DefinirPrazoAction::class)->executar($this->regra->id, $outro['periodos'][1]->id, TipoInstrumento::MAC, '2026-04-10');
    }
}
