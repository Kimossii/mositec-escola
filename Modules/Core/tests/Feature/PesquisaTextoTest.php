<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class PesquisaTextoTest extends TestCase
{
    use RefreshDatabase;

    private function criar(string $nome): void
    {
        Estabelecimento::create(['nome' => $nome, 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
    }

    private function buscar(string $termo): array
    {
        return Estabelecimento::query()->whereContem('nome', $termo)->orderBy('nome')->pluck('nome')->all();
    }

    public function test_ignora_maiusculas_e_minusculas(): void
    {
        $this->criar('João Silva');

        $this->assertSame(['João Silva'], $this->buscar('joão'));
        $this->assertSame(['João Silva'], $this->buscar('SILVA'));
    }

    public function test_encontra_o_termo_em_qualquer_posicao(): void
    {
        $this->criar('Escola Central');

        $this->assertSame(['Escola Central'], $this->buscar('ntra'));
    }

    public function test_percentagem_e_tratada_como_texto_e_nao_como_curinga(): void
    {
        $this->criar('Escola 100%');
        $this->criar('Escola 1000');

        $this->assertSame(['Escola 100%'], $this->buscar('100%'));
    }

    public function test_sublinhado_e_tratado_como_texto_e_nao_como_curinga(): void
    {
        $this->criar('Turma_A');
        $this->criar('TurmaXA');

        $this->assertSame(['Turma_A'], $this->buscar('a_a'));
    }

    public function test_barra_invertida_e_tratada_como_texto(): void
    {
        $this->criar('A\\B');
        $this->criar('AB');

        $this->assertSame(['A\\B'], $this->buscar('\\'));
    }

    public function test_termo_so_com_curingas_nao_devolve_tudo(): void
    {
        $this->criar('Escola A');
        $this->criar('Escola B');

        $this->assertSame([], $this->buscar('%'));
        $this->assertSame([], $this->buscar('_'));
    }

    public function test_or_where_contem_combina_colunas_dentro_de_um_grupo(): void
    {
        $this->criar('Escola Norte');
        Estabelecimento::create(['nome' => 'Outra', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false, 'nome_abreviado' => 'NRT']);
        $this->criar('Sul');

        $resultado = Estabelecimento::query()
            ->where(fn ($q) => $q->whereContem('nome', 'norte')->orWhereContem('nome_abreviado', 'nrt'))
            ->orderBy('nome')->pluck('nome')->all();

        $this->assertSame(['Escola Norte', 'Outra'], $resultado);
    }

    public function test_em_postgres_gera_ilike_com_escape(): void
    {
        $sql = DB::connection('pgsql')->table('estabelecimentos')->whereContem('nome', 'joão')->toSql();

        $this->assertStringContainsString('ilike', $sql);
        $this->assertStringContainsString("escape '\\'", $sql);
    }

    public function test_noutros_motores_gera_like_com_escape(): void
    {
        $sql = DB::table('estabelecimentos')->whereContem('nome', 'joão')->toSql();

        $this->assertStringNotContainsString('ilike', $sql);
        $this->assertStringContainsString("like ? escape '\\'", $sql);
    }
}
