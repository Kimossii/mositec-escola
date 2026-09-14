<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\ConfirmarAnoLectivoRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class ConfirmarAnoLectivoRequestTest extends TestCase
{
    use RefreshDatabase;

    private function novaRequestParaPlano(PlanoCurricular $plano): ConfirmarAnoLectivoRequest
    {
        $request = new ConfirmarAnoLectivoRequest;
        $request->setRouteResolver(fn () => tap(new Route('POST', '/x', []), fn ($r) => $r->bind(new Request)->setParameter('planoCurricular', $plano)));

        return $request;
    }

    public function test_rejeita_ano_lectivo_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoA->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $anoDeOutroEstabelecimento = AnoLectivo::create(['estabelecimento_id' => $estabelecimentoB->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);

        $validator = Validator::make(
            ['ano_lectivo_id' => $anoDeOutroEstabelecimento->id],
            $this->novaRequestParaPlano($plano)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ano_lectivo_id', $validator->errors()->toArray());
    }

    public function test_rejeita_confirmacao_duplicada_do_mesmo_ano_para_o_mesmo_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);

        $validator = Validator::make(
            ['ano_lectivo_id' => $ano->id],
            $this->novaRequestParaPlano($plano)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('ano_lectivo_id', $validator->errors()->toArray());
    }
}
