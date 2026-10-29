<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Tests\TestCase;

class AtribuicaoDocenteModelTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private int $professores = 0;

    private function atribuir(array $cenario, array $atributos = []): AtribuicaoDocente
    {
        return AtribuicaoDocente::create(array_merge([
            'ano_lectivo_id' => $cenario['ano']->id,
            'turma_id' => $cenario['turma']->id,
            'disciplina_id' => $cenario['disciplina']->id,
            'docente_id' => $this->criarDocente('Docente ' . ++$this->professores)->id,
            'papel' => PapelDocente::TITULAR,
            'data_inicio' => '2026-03-01',
        ], $atributos));
    }

    public function test_papel_descricao_sincroniza_ao_gravar(): void
    {
        $atribuicao = $this->atribuir($this->cenarioDeDocencia());

        $this->assertSame('Titular', $atribuicao->fresh()->papel_descricao);

        $atribuicao->update(['papel' => PapelDocente::SUBSTITUTO]);

        $this->assertSame('Substituto', $atribuicao->fresh()->papel_descricao);
    }

    public function test_estado_na_data_deriva_das_datas(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $atribuicao = $this->atribuir($cenario, ['data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);

        $this->assertSame('agendada', $atribuicao->estadoNaData(Carbon::parse('2026-02-28')));
        $this->assertSame('em_curso', $atribuicao->estadoNaData(Carbon::parse('2026-03-01')));
        $this->assertSame('em_curso', $atribuicao->estadoNaData(Carbon::parse('2026-03-31')));
        $this->assertSame('terminada', $atribuicao->estadoNaData(Carbon::parse('2026-04-01')));

        $semFim = $this->atribuir($cenario, ['disciplina_id' => $cenario['outraDisciplina']->id, 'data_fim' => null]);
        $this->assertSame('em_curso', $semFim->estadoNaData(Carbon::parse('2030-01-01')));
    }

    public function test_scope_em_vigor_inclui_os_dois_extremos(): void
    {
        $cenario = $this->cenarioDeDocencia();
        $atribuicao = $this->atribuir($cenario, ['data_inicio' => '2026-03-01', 'data_fim' => '2026-03-31']);
        $semFim = $this->atribuir($cenario, [
            'disciplina_id' => $cenario['outraDisciplina']->id, 'data_inicio' => '2026-06-01', 'data_fim' => null,
        ]);

        $ids = fn (string $dia) => AtribuicaoDocente::emVigor(Carbon::parse($dia))->pluck('id')->all();

        $this->assertSame([], $ids('2026-02-28'));
        $this->assertSame([$atribuicao->id], $ids('2026-03-01'));
        $this->assertSame([$atribuicao->id], $ids('2026-03-31'));
        $this->assertSame([], $ids('2026-04-01'));
        $this->assertSame([$semFim->id], $ids('2026-06-01'));
        $this->assertSame([$semFim->id], $ids('2040-01-01'));
    }

    public function test_pertence_ao_tenant(): void
    {
        $atribuicaoA = $this->atribuir($this->cenarioDeDocencia());
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $atribuicaoB = $this->noTenant($outro, fn () => $this->atribuir($this->cenarioDeDocencia()));

        $this->assertSame([$atribuicaoA->id], AtribuicaoDocente::pluck('id')->all());
        $this->assertNull(AtribuicaoDocente::find($atribuicaoB->id));
        $this->assertSame($this->tenant->id, (int) $atribuicaoA->tenant_id);
        $this->assertSame($outro->id, (int) $atribuicaoB->tenant_id);
    }
}
