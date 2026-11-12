<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\GuardarDefinicoesDeAvaliacaoAction;
use Modules\Avaliacao\DTO\DefinicoesDeAvaliacaoDTO;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\ResultadoDisciplinaService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

/** A escola decide, na regra da classe, quantas avaliações contínuas há por trimestre e quantas têm de ter nota. */
class LimitesDeAvaliacoesTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
    }

    private function regra(int $max, int $min): void
    {
        app(GuardarDefinicoesDeAvaliacaoAction::class)->executar(new DefinicoesDeAvaliacaoDTO($max, $min));
    }

    private function criar(TipoInstrumento $tipo = TipoInstrumento::MAC): AvaliacaoInstrumento
    {
        return app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($this->c['turma']->id, $this->c['disciplina']->id, $this->c['periodos'][1]->id, $tipo, ''));
    }

    private function resultado(int $inscricaoId)
    {
        return app(ResultadoDisciplinaService::class)->calcularTurma($this->c['turma']->id, $this->c['disciplina']->id)[$inscricaoId];
    }

    private function nota(AvaliacaoInstrumento $i, $inscricao, string $valor): void
    {
        AvaliacaoNota::create(['instrumento_id' => $i->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => $valor, 'estado' => EstadoNota::LANCADA]);
    }

    public function test_o_maximo_impede_de_criar_mais_avaliacoes_continuas_mas_nao_a_prova(): void
    {
        $this->regra(2, 1);
        $this->criar();
        $this->criar();

        try {
            $this->criar();
            $this->fail('Devia recusar a terceira avaliação contínua.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no máximo 2', $e->errors()['tipo'][0]);
        }

        $this->assertSame('Prova trimestral', $this->criar(TipoInstrumento::PT)->nome);
    }

    public function test_sem_nada_guardado_valem_10_avaliacoes_no_maximo_e_5_no_minimo(): void
    {
        \Modules\Avaliacao\Models\AvaliacaoDefinicoes::query()->delete();
        $this->assertSame([10, 5], [app(\Modules\Avaliacao\Services\DefinicoesDeAvaliacaoService::class)->maximo(), app(\Modules\Avaliacao\Services\DefinicoesDeAvaliacaoService::class)->minimo()]);

        foreach (range(1, 10) as $_) {
            $this->criar();
        }

        $this->expectException(ValidationException::class);
        $this->criar();
    }

    public function test_o_minimo_da_escola_nao_altera_a_media_so_conta_as_avaliacoes_criadas(): void
    {
        $this->regra(10, 5);
        $inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->nota($this->criar(), $inscricao, '8');
        $this->nota($this->criar(TipoInstrumento::PT), $inscricao, '6');

        $this->travelTo('2026-12-31 10:00:00');
        $r = $this->resultado($inscricao->id);

        $this->assertFalse($r->pendentes[1], 'uma só avaliação criada e lançada: nada pendente, mesmo com mínimo 5');
        $this->assertSame(8.0, round((float) $r->mac[1], 2), 'não se contam zeros por avaliações que ninguém criou');
    }

    public function test_avaliacao_criada_e_nao_lancada_conta_zero_depois_do_prazo_e_e_pendente_antes(): void
    {
        $this->regra(10, 1);
        $inscricao = $this->inscreverAluno($this->c, 'Ana');
        $this->nota($this->criar(), $inscricao, '8');
        $this->criar(); // criada, ninguém lançou
        $this->nota($this->criar(TipoInstrumento::PT), $inscricao, '6');

        $this->travelTo('2026-03-15 10:00:00');
        $this->assertTrue($this->resultado($inscricao->id)->pendentes[1]);

        $this->travelTo('2026-12-31 10:00:00');
        $this->assertSame(4.0, round((float) $this->resultado($inscricao->id)->mac[1], 2), '(8 + 0) / 2');
    }

    public function test_a_configuracao_guarda_uma_so_vez_para_a_escola_e_recusa_valores_invalidos(): void
    {
        \Modules\Avaliacao\Models\AvaliacaoDefinicoes::query()->delete();
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $props = fn () => \Inertia\Testing\AssertableInertia::fromTestResponse($this->actingAs($admin)->get('/avaliacao/configuracao'))->toArray()['props'];

        $this->assertSame(['max_avaliacoes' => 10, 'min_avaliacoes' => 5], $props()['definicoes'], 'por defeito 10 e 5');

        $this->actingAs($admin)->post('/avaliacao/configuracao/definicoes', ['max_avaliacoes' => 8, 'min_avaliacoes' => 4])->assertSessionHasNoErrors();
        $this->assertSame(['max_avaliacoes' => 8, 'min_avaliacoes' => 4], $props()['definicoes']);

        $this->actingAs($admin)->post('/avaliacao/configuracao/definicoes', ['max_avaliacoes' => 3, 'min_avaliacoes' => 5])->assertSessionHasErrors('min_avaliacoes');
        $this->actingAs($admin)->post('/avaliacao/configuracao/definicoes', ['max_avaliacoes' => null, 'min_avaliacoes' => null])->assertSessionHasErrors(['max_avaliacoes', 'min_avaliacoes']);
        $this->assertSame(['max_avaliacoes' => 8, 'min_avaliacoes' => 4], $props()['definicoes'], 'um pedido inválido não altera nada');

        $professor = $this->professorAtribuido($this->c);
        $this->actingAs($professor)->post('/avaliacao/configuracao/definicoes', ['max_avaliacoes' => 2, 'min_avaliacoes' => 1])->assertForbidden();
    }

    public function test_o_mesmo_limite_vale_para_todas_as_classes_e_conta_por_turma_e_disciplina(): void
    {
        $this->regra(1, 1);
        $outra = $this->cenarioDeAvaliacao('PRI_4', 'B');
        $criarNaOutra = fn () => app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($outra['turma']->id, $outra['disciplina']->id, $outra['periodos'][1]->id, TipoInstrumento::MAC, ''));

        $this->criar();
        $criarNaOutra(); // a outra classe tem a sua própria contagem, com o mesmo limite

        $this->expectException(ValidationException::class);
        $criarNaOutra();
    }
}
