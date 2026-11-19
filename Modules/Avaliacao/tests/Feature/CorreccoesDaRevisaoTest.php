<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Enums\FasePauta;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Services\PautaDocumentoService;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

/** Regressões apontadas pela revisão do módulo. */
class CorreccoesDaRevisaoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    public function test_a_pauta_do_recurso_exportada_e_a_do_recurso_e_nao_a_da_1_fase(): void
    {
        $c = $this->cenarioDeAvaliacao();
        app(\Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction::class)->executar(new \Modules\Avaliacao\DTO\RegraAvaliacaoDTO($c['ano']->id, [$c['nivel']->id], 10, '5'));

        $normal = app(PautaDocumentoService::class)->paraTurma($c['turma']->id);
        $recurso = app(PautaDocumentoService::class)->paraTurma($c['turma']->id, FasePauta::RECURSO);

        $this->assertStringNotContainsString('RECURSO', $normal->titulo);
        $this->assertStringContainsString('RECURSO', $recurso->titulo);
    }

    public function test_regra_qualitativa_sem_escala_bloqueia_a_pauta_em_vez_de_dar_erro(): void
    {
        [$c] = $this->cenarioQualitativo();
        AvaliacaoRegraQualitativa::query()->update(['escala_id' => null]);

        $pauta = app(PautaFinalService::class)->montar($c['turma']->id);

        $this->assertFalse($pauta['pode_aprovar']);
        $this->assertStringContainsString('escala', $pauta['bloqueios'][0]);
    }

    public function test_turma_qualitativa_nao_tem_pauta_de_recurso(): void
    {
        [$c] = $this->cenarioQualitativo();
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $viva = app(PautaFinalService::class)->montar($c['turma']->id, FasePauta::RECURSO);
        $this->assertFalse($viva['pode_aprovar']);

        $this->expectException(ValidationException::class);
        try {
            app(AprovarPautaAction::class)->executar($c['turma']->id, FasePauta::RECURSO);
        } finally {
            $this->assertSame(0, AvaliacaoPauta::query()->count());
        }
    }
}
