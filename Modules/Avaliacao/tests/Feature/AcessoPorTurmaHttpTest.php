<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoCompetenciaRegisto;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

/**
 * Um professor só escreve nas turmas e disciplinas que lecciona, em TODOS os pontos de escrita, e o acesso decide-se antes de
 * qualquer validação: um erro nunca revela dados de uma turma alheia (resposta 403, não 422 com pormenores).
 */
class AcessoPorTurmaHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $minha;

    private array $alheia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-03-15 10:00:00');
        $this->minha = $this->cenarioDeAvaliacao('PRI_5', 'M');
        $this->alheia = $this->cenarioDeAvaliacao('PRI_6', 'X');
        foreach ([$this->minha, $this->alheia] as $c) {
            app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($c['ano']->id, [$c['nivel']->id], 10, '5'));
        }
    }

    public function test_o_professor_nao_escreve_em_nenhum_ponto_na_turma_que_nao_lecciona(): void
    {
        $professor = $this->professorAtribuido($this->minha); // lecciona só a turma «M»
        $inscricao = $this->inscreverAluno($this->alheia, 'Alheia');
        $instrumento = app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($this->alheia['turma']->id, $this->alheia['disciplina']->id, $this->alheia['periodos'][1]->id, TipoInstrumento::MAC, 'A1'));
        $this->notasCompletas($this->alheia, $inscricao, [1 => ['8', '8'], 2 => ['8', '8'], 3 => ['8', '8']]);
        $turma = $this->alheia['turma']->id;
        $disciplina = $this->alheia['disciplina']->id;

        $this->actingAs($professor);
        $tentativas = [
            'lançar nota' => ['/avaliacao/notas', ['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '7']],
            'lançar com valor inválido' => ['/avaliacao/notas', ['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '99']],
            'lançar exame' => ['/avaliacao/notas/exame', ['turma_id' => $turma, 'disciplina_id' => $disciplina, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '2']],
            'lançar recurso' => ['/avaliacao/notas/recurso', ['turma_id' => $turma, 'disciplina_id' => $disciplina, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '8']],
            'criar avaliação' => ['/avaliacao/notas/instrumentos', ['turma_id' => $turma, 'disciplina_id' => $disciplina, 'periodo_id' => $this->alheia['periodos'][1]->id, 'tipo' => 0, 'nome' => 'Intrusa', 'obrigatorio' => true]],
        ];

        foreach ($tentativas as $nome => [$url, $dados]) {
            $this->post($url, $dados)->assertForbidden("«{$nome}» devia dar 403 a quem não lecciona a turma.");
        }
        $this->delete("/avaliacao/notas/instrumentos/{$instrumento->id}")->assertForbidden();

        $this->assertSame(0, AvaliacaoNota::whereHas('instrumento', fn ($q) => $q->whereIn('tipo', [TipoInstrumento::EXAME->value, TipoInstrumento::RECURSO->value]))->count());
        $this->assertSame(0, AvaliacaoInstrumento::where('turma_id', $turma)->whereIn('tipo', [TipoInstrumento::EXAME->value, TipoInstrumento::RECURSO->value])->count());
        $this->assertSame(1, AvaliacaoInstrumento::where('turma_id', $turma)->where('nome', 'A1')->count()); // a avaliação alheia não foi apagada nem duplicada
    }

    public function test_o_mesmo_vale_para_o_qualitativo_e_as_competencias(): void
    {
        [$q, $escala] = $this->cenarioQualitativo('Q');
        $this->guardarCompetencias($q['disciplina']->id, null, [['descricao' => 'Conta até 5']]);
        $inscricao = $this->inscreverAluno($q, 'Crianca');
        $this->actingAs($this->professorAtribuido($this->minha, 'prof@example.com')); // não lecciona a turma «Q»

        $this->post('/avaliacao/notas/qualitativas', ['inscricao_disciplina_id' => $inscricao->id, 'periodo_id' => $q['periodos'][1]->id, 'escala_nivel_id' => $escala->niveis[0]->id])->assertForbidden();
        $this->post('/avaliacao/notas/qualitativas', ['inscricao_disciplina_id' => $inscricao->id, 'periodo_id' => 999999, 'escala_nivel_id' => 999999])->assertForbidden();
        $this->post('/avaliacao/notas/competencias', [
            'inscricao_disciplina_id' => $inscricao->id, 'competencia_id' => AvaliacaoCompetencia::firstOrFail()->id, 'periodo_id' => $q['periodos'][1]->id, 'escala_nivel_id' => $escala->niveis[0]->id,
        ])->assertForbidden();

        $this->assertSame(0, AvaliacaoQualitativa::count());
        $this->assertSame(0, AvaliacaoCompetenciaRegisto::count());
    }

    public function test_quem_lecciona_a_turma_continua_a_escrever_nela(): void
    {
        $professor = $this->professorAtribuido($this->minha, 'dono@example.com');
        $inscricao = $this->inscreverAluno($this->minha, 'Minha');
        $instrumento = app(CriarInstrumentoAction::class)->executar(new InstrumentoDTO($this->minha['turma']->id, $this->minha['disciplina']->id, $this->minha['periodos'][1]->id, TipoInstrumento::MAC, 'A1'));

        $this->actingAs($professor)->post('/avaliacao/notas', ['instrumento_id' => $instrumento->id, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '7'])->assertRedirect();

        $this->assertSame('7.00', AvaliacaoNota::firstOrFail()->valor);
    }

    public function test_quem_nao_tem_sessao_nao_escreve(): void
    {
        $this->post('/avaliacao/notas', ['instrumento_id' => 1, 'inscricao_disciplina_id' => 1, 'valor' => '7'])->assertRedirect();
    }
}
