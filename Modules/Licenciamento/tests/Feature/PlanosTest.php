<?php

namespace Modules\Licenciamento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Licenciamento\Actions\AtualizarPlanoAction;
use Modules\Licenciamento\Actions\CriarPlanoAction;
use Modules\Licenciamento\DTOs\PlanoDTO;
use Modules\Licenciamento\Enums\Modalidade;
use Modules\Licenciamento\Exceptions\DadosDePlanoInvalidos;
use Modules\Licenciamento\Models\Plano;
use Tests\TestCase;

class PlanosTest extends TestCase
{
    use RefreshDatabase;

    private function dto(array $sobrepor = []): PlanoDTO
    {
        return new PlanoDTO(...array_merge([
            'nome' => 'Básico',
            'modulos' => ['financeiro'],
            'validadeDias' => 365,
            'modalidade' => Modalidade::ONLINE,
            'limites' => ['alunos' => 200],
        ], $sobrepor));
    }

    public function test_cria_plano_com_descricao_da_modalidade(): void
    {
        $plano = app(CriarPlanoAction::class)->executar($this->dto());

        $this->assertSame('Básico', $plano->nome);
        $this->assertSame('Online', $plano->modalidade_descricao);
        $this->assertSame(200, $plano->limite_alunos);
        $this->assertNull($plano->limite_utilizadores);
        $this->assertNull($plano->tolerancia_dias);
    }

    public function test_plano_sem_modulos_ou_limites_negativos_e_recusado(): void
    {
        foreach ([
            ['modulos' => []],
            ['limites' => ['alunos' => -1]],
            ['toleranciaDias' => -1],
            ['nome' => '  '],
        ] as $caso) {
            try {
                app(CriarPlanoAction::class)->executar($this->dto($caso));
                $this->fail('Devia recusar '.json_encode($caso));
            } catch (DadosDePlanoInvalidos $e) {
                $this->assertNotEmpty($e->erros);
            }
        }
        $this->assertSame(0, Plano::query()->count());
    }

    public function test_modulo_fora_da_lista_configurada_e_recusado(): void
    {
        try {
            app(CriarPlanoAction::class)->executar($this->dto(['modulos' => ['inexistente']]));
            $this->fail('Devia recusar');
        } catch (DadosDePlanoInvalidos $e) {
            $this->assertArrayHasKey('modulos', $e->erros);
        }

        // A lista é extensível por configuração.
        config(['licenciamento.modulos_licenciaveis' => ['financeiro', 'avaliacao', 'inexistente']]);
        $this->assertSame(['inexistente'], app(CriarPlanoAction::class)->executar($this->dto(['modulos' => ['inexistente']]))->modulos);
    }

    public function test_tolerancia_zero_e_aceite_no_plano(): void
    {
        $plano = app(CriarPlanoAction::class)->executar($this->dto(['toleranciaDias' => 0]));
        $this->assertSame(0, $plano->tolerancia_dias);
    }

    public function test_atualiza_plano(): void
    {
        $plano = app(CriarPlanoAction::class)->executar($this->dto());

        $plano = app(AtualizarPlanoAction::class)->executar($plano, $this->dto(['nome' => 'Pro', 'modalidade' => Modalidade::LOCAL]));

        $this->assertSame('Pro', $plano->fresh()->nome);
        $this->assertSame('Local', $plano->fresh()->modalidade_descricao);

        $this->expectException(DadosDePlanoInvalidos::class);
        app(AtualizarPlanoAction::class)->executar($plano, $this->dto(['modulos' => []]));
    }
}
