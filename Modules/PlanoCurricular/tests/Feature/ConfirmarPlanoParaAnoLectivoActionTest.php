<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\ConfirmarPlanoParaAnoLectivoAction;
use Modules\PlanoCurricular\DTO\ConfirmarAnoLectivoDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ConfirmarPlanoParaAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirma_plano_regista_quem_e_quando(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => Hash::make('password')]);

        $confirmacao = (new ConfirmarPlanoParaAnoLectivoAction())->executar(
            $plano,
            new ConfirmarAnoLectivoDTO(ano_lectivo_id: $ano->id, observacoes: 'Sem alterações face ao ano anterior.'),
            $user->id,
        );

        $this->assertSame($ano->id, $confirmacao->ano_lectivo_id);
        $this->assertSame($user->id, $confirmacao->confirmado_por);
        $this->assertNotNull($confirmacao->confirmado_em);
        $this->assertSame('Sem alterações face ao ano anterior.', $confirmacao->observacoes);
    }
}
