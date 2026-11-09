<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\EliminarInstrumentoAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Tests\TestCase;

class InstrumentoActionsTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function dto(array $c, TipoInstrumento $tipo, string $nome, bool $obrigatorio = true, int $trimestre = 1): InstrumentoDTO
    {
        return new InstrumentoDTO($c['turma']->id, $c['disciplina']->id, $c['periodos'][$trimestre]->id, $tipo, $nome, $obrigatorio);
    }

    public function test_cria_quatro_continuas_e_uma_pt(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $acao = app(CriarInstrumentoAction::class);

        foreach ([1, 2, 3] as $n) {
            $acao->executar($this->dto($c, TipoInstrumento::MAC, "Avaliação {$n}"));
        }
        $acao->executar($this->dto($c, TipoInstrumento::MAC, 'Avaliação 4', obrigatorio: false));
        $pt = $acao->executar($this->dto($c, TipoInstrumento::PT, 'Prova do trimestre'));

        $this->assertSame(5, AvaliacaoInstrumento::count());
        $this->assertSame(1, AvaliacaoInstrumento::where('obrigatorio', false)->count());
        $this->assertSame('Prova trimestral', $pt->tipo_descricao);
    }

    public function test_segunda_pt_no_mesmo_periodo_e_rejeitada_mas_noutro_trimestre_passa(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $acao = app(CriarInstrumentoAction::class);
        $acao->executar($this->dto($c, TipoInstrumento::PT, 'PT1'));
        $acao->executar($this->dto($c, TipoInstrumento::PT, 'PT2', trimestre: 2));

        $this->expectException(ValidationException::class);
        $acao->executar($this->dto($c, TipoInstrumento::PT, 'PT1 outra'));
    }

    public function test_periodo_de_outro_ano_lectivo_e_rejeitado(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $outro = $this->cenarioDeAvaliacao('PRI_4', 'Z');

        $this->expectException(ValidationException::class);
        app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($c['turma']->id, $c['disciplina']->id, $outro['periodos'][1]->id, TipoInstrumento::MAC, 'X'));
    }

    public function test_eliminar_sem_notas_funciona_e_com_notas_e_rejeitado(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $acao = app(CriarInstrumentoAction::class);
        $livre = $acao->executar($this->dto($c, TipoInstrumento::MAC, 'Livre'));
        $comNota = $acao->executar($this->dto($c, TipoInstrumento::MAC, 'Com nota'));
        AvaliacaoNota::create([
            'instrumento_id' => $comNota->id, 'inscricao_disciplina_id' => $this->inscreverAluno($c)->id,
            'valor' => '7', 'estado' => EstadoNota::LANCADA,
        ]);

        app(EliminarInstrumentoAction::class)->executar($livre->id);
        $this->assertSame(1, AvaliacaoInstrumento::count());

        $this->expectException(ValidationException::class);
        app(EliminarInstrumentoAction::class)->executar($comNota->id);
    }

    public function test_instrumento_nao_tem_coluna_de_prazo(): void
    {
        $this->assertFalse(Schema::hasColumn('avaliacao_instrumentos', 'prazo'));
        $this->assertFalse(Schema::hasColumn('avaliacao_instrumentos', 'data_limite'));
    }
}
