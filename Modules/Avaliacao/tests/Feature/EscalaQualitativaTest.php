<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Models\AvaliacaoEscala;
use Modules\Avaliacao\Models\AvaliacaoEscalaNivel;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Tests\TestCase;

class EscalaQualitativaTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private const NIVEIS = [['codigo' => 'INS', 'designacao' => 'Insuficiente'], ['codigo' => 'SUF', 'designacao' => 'Suficiente'], ['codigo' => 'B', 'designacao' => 'Bom']];

    private function guardar(?int $id = null, string $nome = 'Escala A', array $niveis = self::NIVEIS): AvaliacaoEscala
    {
        return $this->guardarEscala($id, $nome, $niveis);
    }

    public function test_cria_escala_com_niveis_pela_ordem_recebida(): void
    {
        $escala = $this->guardar();

        $this->assertSame(['INS', 'SUF', 'B'], $escala->niveis->pluck('codigo')->all());
        $this->assertSame([1, 2, 3], $escala->niveis->pluck('ordem')->all());
    }

    public function test_valida_nome_numero_de_niveis_codigos_repetidos_e_campos_vazios(): void
    {
        $invalidos = [
            ['', self::NIVEIS], ['X', [self::NIVEIS[0]]],
            ['X', [self::NIVEIS[0], ['codigo' => 'ins', 'designacao' => 'Outro']]],
            ['X', [self::NIVEIS[0], ['codigo' => 'B', 'designacao' => ' ']]],
        ];

        foreach ($invalidos as [$nome, $niveis]) {
            try {
                $this->guardar(null, $nome, $niveis);
                $this->fail('Esperava ValidationException.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(0, AvaliacaoEscala::count());
    }

    public function test_nome_duplicado_e_rejeitado(): void
    {
        $this->guardar();

        $this->expectException(ValidationException::class);
        $this->guardar(null, 'Escala A');
    }

    public function test_actualizar_mantem_os_ids_e_permite_reordenar(): void
    {
        $escala = $this->guardar();
        [$a, $b, $c] = $escala->niveis->all();

        $nova = $this->guardar($escala->id, 'Escala A', [
            ['id' => $c->id, 'codigo' => 'B', 'designacao' => 'Bom'],
            ['id' => $a->id, 'codigo' => 'INS', 'designacao' => 'Insuficiente (renomeado)'],
            ['id' => $b->id, 'codigo' => 'SUF', 'designacao' => 'Suficiente'],
        ]);

        $this->assertSame([$c->id, $a->id, $b->id], $nova->niveis->pluck('id')->all());
        $this->assertSame('Insuficiente (renomeado)', AvaliacaoEscalaNivel::find($a->id)->designacao);
    }

    public function test_nivel_ja_usado_nao_se_remove(): void
    {
        $c = $this->cenarioDeAvaliacao('PRE_I');
        $escala = $this->guardar();
        AvaliacaoQualitativa::create(['inscricao_disciplina_id' => $this->inscreverAluno($c)->id, 'periodo_id' => $c['periodos'][1]->id, 'escala_nivel_id' => $escala->niveis[2]->id]);

        $this->expectException(ValidationException::class);
        $this->guardar($escala->id, 'Escala A', [['id' => $escala->niveis[0]->id, 'codigo' => 'INS', 'designacao' => 'Insuficiente'], ['id' => $escala->niveis[1]->id, 'codigo' => 'SUF', 'designacao' => 'Suficiente']]);
    }

    public function test_regra_qualitativa_so_para_creche_e_pre_escolar(): void
    {
        $escala = $this->guardar();
        $pre = $this->cenarioDeAvaliacao('PRE_I', 'P');
        $pre['nivel']->update(['etapa_ensino' => \Modules\Estabelecimento\Enums\EtapaEnsinoEnum::PRE_ESCOLAR]);
        $primario = $this->cenarioDeAvaliacao('PRI_3', 'Q');
        $regra = $this->guardarRegraQualitativa($pre['ano']->id, $pre['nivel']->id, $escala->id);
        $this->assertSame($escala->id, $regra->escala_id);
        $this->guardarRegraQualitativa($pre['ano']->id, $pre['nivel']->id, $escala->id);
        $this->assertSame(1, AvaliacaoRegraQualitativa::count());

        $this->expectException(ValidationException::class);
        $this->guardarRegraQualitativa($primario['ano']->id, $primario['nivel']->id, $escala->id);
    }

    public function test_escala_de_outro_tenant_nao_se_usa(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idB = $this->noTenant($outro, fn () => $this->guardar(null, 'Escala B')->id);
        $c = $this->cenarioDeAvaliacao('PRE_I');
        $c['nivel']->update(['etapa_ensino' => \Modules\Estabelecimento\Enums\EtapaEnsinoEnum::PRE_ESCOLAR]);

        $this->assertSame(0, AvaliacaoEscala::count());
        $this->expectException(ModelNotFoundException::class);
        $this->guardarRegraQualitativa($c['ano']->id, $c['nivel']->id, $idB);
    }
}
