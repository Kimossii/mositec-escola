<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Enums\EstadoNota;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\ResultadoDisciplinaService;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Matricula\Models\InscricaoDisciplina;
use Tests\TestCase;

class ResultadoDisciplinaServiceTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private InscricaoDisciplina $inscricao;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO(
            ano_lectivo_id: $this->c['ano']->id, nivel_ids: [$this->c['nivel']->id], escala_maxima: 10, nota_minima: '5',
        ));
        $this->inscricao = $this->inscreverAluno($this->c);
        $this->travelTo('2026-03-15 10:00:00');
    }

    private function instrumento(int $trimestre, TipoInstrumento $tipo, string $nome, bool $obrigatorio = true): AvaliacaoInstrumento
    {
        return AvaliacaoInstrumento::create([
            'turma_id' => $this->c['turma']->id, 'disciplina_id' => $this->c['disciplina']->id, 'periodo_id' => $this->c['periodos'][$trimestre]->id,
            'tipo' => $tipo, 'nome' => $nome, 'obrigatorio' => $obrigatorio,
        ]);
    }

    private function nota(AvaliacaoInstrumento $i, ?string $valor, EstadoNota $estado = EstadoNota::LANCADA): void
    {
        AvaliacaoNota::create(['instrumento_id' => $i->id, 'inscricao_disciplina_id' => $this->inscricao->id, 'valor' => $valor, 'estado' => $estado]);
    }

    private function resultado()
    {
        return app(ResultadoDisciplinaService::class)->calcular($this->inscricao->id);
    }

    /** Trimestre completo: uma contínua e a PT, com MT = (mac + pt) / 2. */
    private function trimestre(int $n, string $mac, string $pt): void
    {
        $this->nota($this->instrumento($n, TipoInstrumento::MAC, "A{$n}"), $mac);
        $this->nota($this->instrumento($n, TipoInstrumento::PT, "PT{$n}"), $pt);
    }

    public function test_exemplo_ana_mac_7_50(): void
    {
        foreach (['7', '8', '6'] as $i => $v) {
            $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A'.($i + 1)), $v);
        }
        $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A4', obrigatorio: false), '9');

        $this->assertSame('7.5000000000', $this->resultado()->mac[1]);
    }

    public function test_exemplo_joao_mac_3_com_facultativa_vazia_excluida(): void
    {
        foreach (['5', '4', '0'] as $i => $v) {
            $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A'.($i + 1)), $v);
        }
        $this->instrumento(1, TipoInstrumento::MAC, 'A4', obrigatorio: false);

        $r = $this->resultado();

        $this->assertSame('3.0000000000', $r->mac[1]);
        $this->assertFalse($r->pendentes[1] && $r->mac[1] === null);
    }

    public function test_antes_do_prazo_obrigatoria_em_falta_deixa_mt_null_e_pendente(): void
    {
        $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A1'), '8');
        $this->instrumento(1, TipoInstrumento::MAC, 'A2');
        $this->nota($this->instrumento(1, TipoInstrumento::PT, 'PT1'), '6');

        $r = $this->resultado();

        $this->assertNull($r->mt[1]);
        $this->assertTrue($r->pendentes[1]);
        $this->assertSame('8.0000000000', $r->mac[1]);
    }

    public function test_aluno_sem_nenhuma_nota_antes_do_prazo_tem_mt_null_nao_zero(): void
    {
        $this->instrumento(1, TipoInstrumento::MAC, 'A1');
        $this->instrumento(1, TipoInstrumento::PT, 'PT1');

        $r = $this->resultado();

        $this->assertNull($r->mac[1]);
        $this->assertNull($r->mt[1]);
        $this->assertTrue($r->pendentes[1]);
    }

    public function test_depois_do_prazo_obrigatoria_em_falta_vale_zero(): void
    {
        $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A1'), '8');
        $this->instrumento(1, TipoInstrumento::MAC, 'A2');
        $this->nota($this->instrumento(1, TipoInstrumento::PT, 'PT1'), '6');
        $this->travelTo('2026-05-02 10:00:00');

        $r = $this->resultado();

        $this->assertSame('4.0000000000', $r->mac[1]);
        $this->assertSame('5.0000000000', $r->mt[1]);
        $this->assertFalse($r->pendentes[1]);
    }

    public function test_pt_em_falta_depois_do_prazo_vale_zero(): void
    {
        $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A1'), '8');
        $this->instrumento(1, TipoInstrumento::PT, 'PT1');
        $this->travelTo('2026-05-02 10:00:00');

        $r = $this->resultado();

        $this->assertSame('0.0000000000', $r->pt[1] === null ? null : bcadd($r->pt[1], '0', 10));
        $this->assertSame('4.0000000000', $r->mt[1]);
    }

    public function test_nota_anulada_nao_entra_e_anulada_conta_zero_entra(): void
    {
        $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A1'), '8');
        $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A2'), null, EstadoNota::ANULADA);
        $this->nota($this->instrumento(1, TipoInstrumento::PT, 'PT1'), '6');

        $this->assertSame('8.0000000000', $this->resultado()->mac[1]);

        AvaliacaoNota::where('estado', EstadoNota::ANULADA->value)->update(['estado' => EstadoNota::ANULADA_CONTA_ZERO->value]);

        $this->assertSame('4.0000000000', $this->resultado()->mac[1]);
    }

    public function test_pt_anulada_deixa_a_mt_pendente_e_nao_vira_zero(): void
    {
        $this->nota($this->instrumento(1, TipoInstrumento::MAC, 'A1'), '8');
        $this->nota($this->instrumento(1, TipoInstrumento::PT, 'PT1'), null, EstadoNota::ANULADA);
        $this->travelTo('2026-05-02 10:00:00');

        $r = $this->resultado();

        $this->assertNull($r->mt[1]);
        $this->assertTrue($r->pendentes[1]);
    }

    public function test_mfd_so_com_tres_mt(): void
    {
        $this->trimestre(1, '5.33', '7');
        $this->trimestre(2, '6', '7');

        $r = $this->resultado();

        $this->assertNotNull($r->mt[2]);
        $this->assertNull($r->mt[3]);
        $this->assertNull($r->mfd);
        $this->assertNull($r->mfdArredondada);
    }

    public function test_mfd_7_055_com_mt_6_165_6_5_8_5(): void
    {
        $this->trimestre(1, '5.33', '7');
        $this->trimestre(2, '6', '7');
        $this->trimestre(3, '8', '9');

        $r = $this->resultado();

        $this->assertSame('6.1650000000', $r->mt[1]);
        $this->assertSame('6.5000000000', $r->mt[2]);
        $this->assertSame('8.5000000000', $r->mt[3]);
        $this->assertSame('7.0550000000', $r->mfd);
        $this->assertSame('7.06', $r->mfdArredondada);
    }

    public function test_calcular_turma_devolve_um_resultado_por_aluno(): void
    {
        $this->trimestre(1, '5.33', '7');
        $outra = $this->inscreverAluno($this->c, 'Outro');

        $todos = app(ResultadoDisciplinaService::class)->calcularTurma($this->c['turma']->id, $this->c['disciplina']->id);

        $this->assertEqualsCanonicalizing([$this->inscricao->id, $outra->id], array_keys($todos));
        $this->assertSame('6.1650000000', $todos[$this->inscricao->id]->mt[1]);
        $this->assertNull($todos[$outra->id]->mt[1]);
    }

    public function test_resultado_nao_altera_a_bd(): void
    {
        $this->trimestre(1, '5.33', '7');
        $escritas = [];
        DB::listen(function ($q) use (&$escritas) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                $escritas[] = $q->sql;
            }
        });

        $this->resultado();

        $this->assertSame([], $escritas);
    }
}
