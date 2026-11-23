<?php

namespace Modules\Frequencia\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\EventoFrequencia;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Models\RegistoHistorico;
use Modules\Frequencia\Tests\Support\CenarioDeFrequencia;
use Tests\TestCase;

class FrequenciaModelosTest extends TestCase
{
    use CenarioDeFrequencia;
    use RefreshDatabase;

    private function aula(array $c, array $o = []): Aula
    {
        return Aula::create(array_merge([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'tipo' => TipoAula::TEMPO,
            'data' => '2026-03-02', 'horario_id' => $c['tempo1']->id, 'disciplina_id' => $c['disciplina']->id,
            'docente_id' => $c['docente']->id,
        ], $o));
    }

    public function test_aula_guarda_descricoes(): void
    {
        $aula = $this->aula($this->cenarioDeFrequencia())->fresh();

        $this->assertSame('Tempo', $aula->tipo_descricao);
        $this->assertSame('Realizada', $aula->estado_descricao);
    }

    public function test_unicidade_de_aula_por_turma_data_e_tempo(): void
    {
        $c = $this->cenarioDeFrequencia();
        $this->aula($c);

        $this->aula($c, ['horario_id' => $c['tempo2']->id]);
        $this->expectException(QueryException::class);

        $this->aula($c);
    }

    public function test_unicidade_de_aula_do_dia_por_turma_e_data(): void
    {
        $c = $this->cenarioDeFrequencia();
        $dia = ['tipo' => TipoAula::DIA, 'horario_id' => null, 'disciplina_id' => null];
        $this->aula($c, $dia + ['data' => '2026-03-02']);

        $this->aula($c, $dia + ['data' => '2026-03-03']);
        $this->expectException(QueryException::class);

        $this->aula($c, $dia + ['data' => '2026-03-02']);
    }

    public function test_registo_unico_por_aula_e_matricula(): void
    {
        $c = $this->cenarioDeFrequencia();
        $aula = $this->aula($c);
        $dados = ['aula_id' => $aula->id, 'matricula_id' => $c['alunos'][0]->id, 'estado' => EstadoPresenca::PRESENTE];
        $registo = Registo::create($dados);

        $this->assertSame('Presente', $registo->fresh()->estado_descricao);
        $this->expectException(QueryException::class);

        Registo::create($dados);
    }

    public function test_fk_composta_recusa_matricula_de_outro_tenant(): void
    {
        $c = $this->cenarioDeFrequencia();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $matriculaB = $this->noTenant($outro, fn () => $this->cenarioDeFrequencia()['alunos'][0]);
        $aula = $this->aula($c);

        $this->expectException(QueryException::class);

        Registo::create(['aula_id' => $aula->id, 'matricula_id' => $matriculaB->id, 'estado' => EstadoPresenca::PRESENTE]);
    }

    public function test_isolado_por_tenant(): void
    {
        $aulaA = $this->aula($this->cenarioDeFrequencia());
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $aulaB = $this->noTenant($outro, fn () => $this->aula($this->cenarioDeFrequencia()));

        $this->assertSame([$aulaA->id], Aula::pluck('id')->all());
        $this->assertNull(Aula::find($aulaB->id));
    }

    public function test_historico_nao_tem_updated_at(): void
    {
        $c = $this->cenarioDeFrequencia();
        $registo = Registo::create(['aula_id' => $this->aula($c)->id, 'matricula_id' => $c['alunos'][0]->id, 'estado' => EstadoPresenca::FALTA]);

        $linha = RegistoHistorico::create(['registo_id' => $registo->id, 'evento' => EventoFrequencia::MARCACAO, 'estado_novo' => EstadoPresenca::FALTA])->fresh();

        $this->assertNull(RegistoHistorico::UPDATED_AT);
        $this->assertNotNull($linha->created_at);
        $this->assertSame('Marcação', $linha->evento_descricao);
    }
}
