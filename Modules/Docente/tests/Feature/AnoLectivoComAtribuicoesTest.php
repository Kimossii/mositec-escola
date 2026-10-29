<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Tests\TestCase;

/** Integração de ponta a ponta: o Action real do ano lectivo consulta a dependência do Docente. */
class AnoLectivoComAtribuicoesTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function dto(string $inicio): AnoLectivoDTO
    {
        return new AnoLectivoDTO(nome: '2026', dataInicio: $inicio, dataFim: '2026-12-31', estado: EstadoAnoLectivo::ATIVO);
    }

    private function comAtribuicaoEmMarco(): array
    {
        $c = $this->cenarioDeDocencia();
        AtribuicaoDocente::create([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $this->criarDocente()->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-03-01',
        ]);

        return $c;
    }

    public function test_rejeita_mudar_o_inicio_para_depois_do_comeco_de_uma_atribuicao(): void
    {
        $c = $this->comAtribuicaoEmMarco();

        try {
            app(AtualizarAnoLectivoAction::class)->atualizar($c['ano'], $this->dto('2026-04-01'));
            $this->fail('Devia ter sido rejeitado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('atribuições de docentes', $e->errors()['data_inicio'][0]);
        }

        $this->assertSame('2026-01-01', $c['ano']->fresh()->data_inicio->toDateString());
        $this->assertSame('2026-01-01', $c['ano']->data_inicio->toDateString());
    }

    public function test_aceita_mudar_o_inicio_para_antes_das_atribuicoes(): void
    {
        $c = $this->comAtribuicaoEmMarco();

        $ano = app(AtualizarAnoLectivoAction::class)->atualizar($c['ano'], $this->dto('2025-12-01'));

        $this->assertSame('2025-12-01', $ano->data_inicio->toDateString());
    }
}
