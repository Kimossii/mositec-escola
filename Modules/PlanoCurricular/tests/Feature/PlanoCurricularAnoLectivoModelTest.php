<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PlanoCurricularAnoLectivoModelTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);

        return compact('estabelecimento', 'curso', 'plano');
    }

    public function test_confirma_plano_para_ano_lectivo(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $user = User::create(['name' => 'Confirmador', 'email' => 'confirmador@example.com', 'password' => Hash::make('x')]);
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO->value]);

        $confirmacao = PlanoCurricularAnoLectivo::create([
            'plano_curricular_id' => $plano->id,
            'ano_lectivo_id' => $ano->id,
            'confirmado_em' => now(),
            'confirmado_por' => $user->id,
        ]);

        $this->assertSame($plano->id, $confirmacao->planoCurricular->id);
        $this->assertSame($ano->id, $confirmacao->anoLectivo->id);
        $this->assertSame($user->id, $confirmacao->confirmadoPor->id);
    }

    public function test_impede_duas_associacoes_iguais_no_mesmo_ano(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO->value]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);

        $this->expectException(QueryException::class);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
    }

    public function test_mesmo_plano_reconfirmado_em_anos_diferentes(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $planoA] = $this->contexto();
        $ano2026 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO->value]);
        $ano2027 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2027/2028', 'data_inicio' => '2027-02-01', 'data_fim' => '2027-11-30', 'estado' => EstadoAnoLectivo::ATIVO->value]);

        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2026->id]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2027->id]);

        $this->assertCount(2, $planoA->anosLectivos);
    }

    public function test_historico_suporta_plano_diferente_em_ano_seguinte_sem_alterar_plano_anterior(): void
    {
        ['estabelecimento' => $estabelecimento, 'curso' => $curso, 'plano' => $planoA] = $this->contexto();
        $nivelB = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N11', 'nome' => '11ª Classe', 'ordem' => 2, 'etapa_ensino' => 4]);
        $planoB = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivelB->id, 'curso_id' => $curso->id, 'codigo' => 'PC2', 'nome' => 'Plano B']);

        $ano2026 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO->value]);
        $ano2027 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2027/2028', 'data_inicio' => '2027-02-01', 'data_fim' => '2027-11-30', 'estado' => EstadoAnoLectivo::ATIVO->value]);
        $ano2028 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2028/2029', 'data_inicio' => '2028-02-01', 'data_fim' => '2028-11-30', 'estado' => EstadoAnoLectivo::ATIVO->value]);

        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2026->id]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2027->id]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoB->id, 'ano_lectivo_id' => $ano2028->id]);

        $this->assertSame(['2026/2027', '2027/2028'], $planoA->fresh()->anosLectivos->pluck('anoLectivo.nome')->all());
        $this->assertSame(['2028/2029'], $planoB->fresh()->anosLectivos->pluck('anoLectivo.nome')->all());
        // Plano A não foi tocado ao criar o Plano B — mesma instância, mesmos dados.
        $this->assertSame('Plano A', $planoA->fresh()->nome);
    }
}
