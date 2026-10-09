<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CopiarPlanosPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.planos-propina.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function anoDestino(): AnoLectivo
    {
        return $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
    }

    private function copiar(int $origem, int $destino, array $extra = [])
    {
        return $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'copiar'), array_merge(['ano_origem_id' => $origem, 'ano_destino_id' => $destino], $extra));
    }

    public function test_copia_os_campos_o_ano_destino_o_estado_inactivo_e_os_alvos(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $turno = $this->turno('Noite');
        $original = $this->plano($origem, 'Trimestral', [
            'descricao' => 'Descrição X',
            'periodicidade' => Periodicidade::TRIMESTRAL,
            'intervalo_meses' => 3,
            'valor' => Dinheiro::deUnidadesMenores(1_234_500),
            'mes_inicio' => 9,
            'mes_fim' => 6,
        ], [['nivel' => $nivel, 'curso' => $curso, 'turno' => $turno], ['nivel' => $nivel]]);

        $admin = $this->adminEscola();
        $this->actingAs($admin)->from('/x')
            ->post(route(self::BASE . 'copiar'), ['ano_origem_id' => $origem->id, 'ano_destino_id' => $destino->id])
            ->assertSessionHasNoErrors()->assertRedirect('/x');

        $copia = PlanoPropina::where('ano_lectivo_id', $destino->id)->firstOrFail();
        $this->assertNotSame($original->id, $copia->id);
        $this->assertSame('Trimestral', $copia->nome);
        $this->assertSame('Descrição X', $copia->descricao);
        $this->assertSame(Periodicidade::TRIMESTRAL, $copia->periodicidade);
        $this->assertSame('Trimestral', $copia->periodicidade_descricao);
        $this->assertSame(3, $copia->intervalo_meses);
        $this->assertSame(1_234_500, $copia->valor->unidadesMenores());
        $this->assertSame(9, $copia->mes_inicio);
        $this->assertSame(6, $copia->mes_fim);
        $this->assertSame(Estado::INATIVO->value, $copia->estado);
        $this->assertSame('Inativo', $copia->estado_descricao);
        $this->assertSame($this->tenant->id, $copia->tenant_id);
        $this->assertSame($admin->id, $copia->criado_por);

        $chaves = $copia->alvos->map(fn ($a) => implode('|', [$a->nivel_academico_id, $a->curso_id, $a->turno_id, $a->turma_id]))->sort()->values()->all();
        $this->assertCount(2, $chaves);
        $this->assertContains("{$nivel->id}|{$curso->id}|{$turno->id}|", $chaves);
        $this->assertContains("{$nivel->id}|||", $chaves);
    }

    public function test_origem_fica_intacta(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $original = $this->plano($origem, 'Mensal', [], [['nivel' => $this->nivel('N1')]]);

        $this->copiar($origem->id, $destino->id)->assertSessionHasNoErrors();

        $original->refresh();
        $this->assertSame($origem->id, $original->ano_lectivo_id);
        $this->assertSame(Estado::ATIVO->value, $original->estado);
        $this->assertSame(1, $original->alvos()->count());
        $this->assertSame(1, PlanoPropina::where('ano_lectivo_id', $origem->id)->count());
    }

    public function test_resumo_lista_copiados_e_ignorados(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $this->plano($origem, 'Mensal');
        $this->plano($origem, 'Velho', ['estado' => Estado::INATIVO->value]);

        $resposta = $this->copiar($origem->id, $destino->id);

        $copia = PlanoPropina::where('ano_lectivo_id', $destino->id)->firstOrFail();
        $resposta->assertSessionHas('copia_planos', [
            'copiados' => [['id' => $copia->id, 'nome' => 'Mensal']],
            'ignorados' => [['nome' => 'Velho', 'motivo' => 'Plano inactivo na origem.']],
        ]);
        $resposta->assertSessionHas('success');
        $this->assertSame(1, PlanoPropina::where('ano_lectivo_id', $destino->id)->count());
    }

    public function test_ignora_plano_com_nome_ja_existente_no_destino(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $this->plano($origem, 'Mensal');
        $existente = $this->plano($destino, 'Mensal', ['mes_inicio' => 1, 'mes_fim' => 2]);

        $this->copiar($origem->id, $destino->id)
            ->assertSessionHas('copia_planos.ignorados', [['nome' => 'Mensal', 'motivo' => 'Já existe um plano com este nome no ano lectivo de destino.']]);

        $this->assertSame(1, PlanoPropina::where('ano_lectivo_id', $destino->id)->count());
        $this->assertSame($existente->id, PlanoPropina::where('ano_lectivo_id', $destino->id)->value('id'));
    }

    public function test_ignora_plano_que_colide_com_um_existente_no_destino(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $nivel = $this->nivel('N1');
        $this->plano($origem, 'Mensal', [], [['nivel' => $nivel]]);
        $this->plano($destino, 'Outro Nome', ['estado' => Estado::INATIVO->value], [['nivel' => $nivel]]);

        $this->copiar($origem->id, $destino->id)
            ->assertSessionHas('copia_planos.ignorados', [['nome' => 'Mensal', 'motivo' => 'Já existe outro plano no ano lectivo de destino, com período sobreposto, aplicável aos mesmos alvos.']]);

        $this->assertSame(1, PlanoPropina::where('ano_lectivo_id', $destino->id)->count());
    }

    public function test_dois_planos_copiados_que_colidem_no_calendario_do_destino_copia_so_o_primeiro(): void
    {
        // Disjuntos na origem (Set-Dez/2026 e Jan-Set/2027); no destino (a começar em Jan/2027) ambos incluem Set/2027.
        $origem = $this->anoLectivo();
        $destino = $this->anoLectivo('2027', '2027-01-01', '2027-12-31');
        $a = $this->plano($origem, 'Fase A', ['mes_inicio' => 9, 'mes_fim' => 12]);
        $this->plano($origem, 'Fase B', ['mes_inicio' => 1, 'mes_fim' => 9]);

        $this->copiar($origem->id, $destino->id)
            ->assertSessionHas('copia_planos.copiados.0.nome', 'Fase A')
            ->assertSessionHas('copia_planos.ignorados', [['nome' => 'Fase B', 'motivo' => 'Já existe outro plano no ano lectivo de destino, com período sobreposto, aplicável aos mesmos alvos.']]);

        $this->assertSame(['Fase A'], PlanoPropina::where('ano_lectivo_id', $destino->id)->pluck('nome')->all());
        $this->assertNotNull($a->id);
    }

    public function test_ignora_plano_com_alvo_de_turma(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $turma = $this->turma($origem, $this->nivel('N1'));
        $this->plano($origem, 'Da Turma', [], [['turma' => $turma]]);

        $this->copiar($origem->id, $destino->id)
            ->assertSessionHas('copia_planos.ignorados', [['nome' => 'Da Turma', 'motivo' => 'Aplica-se a uma turma específica, e as turmas pertencem ao ano lectivo de origem: crie o plano manualmente no destino.']]);

        $this->assertSame(0, PlanoPropina::where('ano_lectivo_id', $destino->id)->count());
    }

    public function test_ignora_plano_com_alvo_apagado(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $nivel = $this->nivel('N1');
        $turno = $this->turno('Noite');
        $this->plano($origem, 'Sem Nível', [], [['nivel' => $nivel]]);
        $this->plano($origem, 'Sem Turno', ['mes_inicio' => 1, 'mes_fim' => 2], [['turno' => $turno]]);
        $nivel->delete();
        $turno->delete();

        $this->copiar($origem->id, $destino->id)
            ->assertSessionHas('copia_planos.ignorados', [
                ['nome' => 'Sem Nível', 'motivo' => 'Um dos alvos (nível, curso ou turno) foi eliminado.'],
                ['nome' => 'Sem Turno', 'motivo' => 'Um dos alvos (nível, curso ou turno) foi eliminado.'],
            ]);

        $this->assertSame(0, PlanoPropina::where('ano_lectivo_id', $destino->id)->count());
    }

    public function test_idempotente_segunda_execucao_nao_copia_nada(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $this->plano($origem, 'Mensal');

        $this->copiar($origem->id, $destino->id)->assertSessionHas('copia_planos.copiados.0.nome', 'Mensal');
        $this->copiar($origem->id, $destino->id)
            ->assertSessionHas('copia_planos.copiados', [])
            ->assertSessionHas('copia_planos.ignorados', [['nome' => 'Mensal', 'motivo' => 'Já existe um plano com este nome no ano lectivo de destino.']]);

        $this->assertSame(1, PlanoPropina::where('ano_lectivo_id', $destino->id)->count());
    }

    public function test_origem_igual_ao_destino_e_rejeitada(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Mensal');

        $this->copiar($ano->id, $ano->id)->assertSessionHasErrors(['ano_destino_id' => 'O ano lectivo de destino tem de ser diferente do de origem.']);

        $this->assertSame(1, PlanoPropina::count());
    }

    public function test_ano_de_outro_tenant_ou_apagado_e_rejeitado_e_nada_e_criado(): void
    {
        $origem = $this->anoLectivo();
        $this->plano($origem, 'Mensal');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $doOutro = $this->noTenant($outro, fn () => $this->anoLectivo('2030', '2030-09-01', '2031-07-31'));
        $apagado = $this->anoDestino();
        $apagado->delete();

        $this->copiar($origem->id, $doOutro->id)->assertSessionHasErrors('ano_destino_id');
        $this->copiar($doOutro->id, $origem->id)->assertSessionHasErrors('ano_origem_id');
        $this->copiar($origem->id, $apagado->id)->assertSessionHasErrors('ano_destino_id');
        $this->copiar($apagado->id, $origem->id)->assertSessionHasErrors('ano_origem_id');
        $this->copiar($origem->id, 999999)->assertSessionHasErrors('ano_destino_id');

        $this->assertSame(1, PlanoPropina::withoutGlobalScopes()->count());
    }

    public function test_campos_obrigatorios(): void
    {
        $this->actingAs($this->adminEscola())->from('/x')->post(route(self::BASE . 'copiar'), [])
            ->assertSessionHasErrors([
                'ano_origem_id' => 'O ano lectivo de origem é obrigatório.',
                'ano_destino_id' => 'O ano lectivo de destino é obrigatório.',
            ]);
    }

    public function test_tenant_id_forjado_e_ignorado(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $this->plano($origem, 'Mensal');
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->copiar($origem->id, $destino->id, ['tenant_id' => $outro->id])->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, PlanoPropina::where('ano_lectivo_id', $destino->id)->firstOrFail()->tenant_id);
    }

    public function test_professor_recebe_403(): void
    {
        $origem = $this->anoLectivo();
        $destino = $this->anoDestino();
        $this->plano($origem, 'Mensal');

        $this->actingAs($this->professor())
            ->post(route(self::BASE . 'copiar'), ['ano_origem_id' => $origem->id, 'ano_destino_id' => $destino->id])
            ->assertForbidden();

        $this->assertSame(0, PlanoPropina::where('ano_lectivo_id', $destino->id)->count());
    }
}
