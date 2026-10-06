<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\Concerns\PopulaDadosAcademicos;
use Tests\TestCase;

/**
 * Matriz transversal dos módulos académicos: dois tenants populados em todas as
 * entidades; cada listagem mostra só os dados do domínio e o id do outro tenant dá 404.
 */
class AcademicoIsolamentoTest extends TestCase
{
    use PopulaDadosAcademicos;
    use RefreshDatabase;

    /** Tabelas com estabelecimento_id e tenant_id dos módulos académicos. */
    private const COM_ESTABELECIMENTO = [
        'ano_lectivos', 'cursos', 'disciplinas', 'salas', 'turnos',
        'niveis_academicos', 'planos_curriculares', 'alunos',
    ];

    private Tenant $outro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
    }

    private function administrador(): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => 'admin-a@example.com', 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    private function dadosDeA(): array
    {
        return $this->popularDadosAcademicos('AAA');
    }

    private function dadosDeB(): array
    {
        return $this->noTenant($this->outro, fn () => $this->popularDadosAcademicos('BBB'));
    }

    public function test_cada_listagem_academica_mostra_so_os_dados_do_dominio(): void
    {
        $admin = $this->administrador();
        $this->dadosDeA();
        $this->dadosDeB();

        // Listagem => marca visível (aluno e matrícula partilham o nome do aluno).
        $listagens = [
            '/ano-lectivos' => 'AnoAAA',
            '/cursos' => 'CursoAAA',
            '/disciplinas' => 'DisciplinaAAA',
            '/salas' => 'SalaAAA',
            '/turnos' => 'TurnoAAA',
            '/niveis-academicos' => 'NivelAAA',
            '/turmas?ano_lectivo_id=' => 'TurmaAAA',
            '/alunos' => 'AlunoAAA',
            '/matriculas' => 'AlunoAAA',
        ];

        foreach ($listagens as $caminho => $marcaDeA) {
            $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, $caminho));
            $resposta->assertOk();
            $conteudo = $resposta->getContent();

            $this->assertStringContainsString($marcaDeA, $conteudo, "{$caminho}: falta o dado de A (controlo positivo).");
            $this->assertStringNotContainsString(str_replace('AAA', 'BBB', $marcaDeA), $conteudo, "{$caminho}: apareceu um dado de B.");
        }
    }

    public function test_o_detalhe_de_b_no_dominio_de_a_da_404_e_o_de_a_da_200(): void
    {
        $admin = $this->administrador();
        $a = $this->dadosDeA();
        $b = $this->dadosDeB();

        $detalhes = [
            'ano' => '/ano-lectivos/%d', 'curso' => '/cursos/%d', 'disciplina' => '/disciplinas/%d',
            'sala' => '/salas/%d', 'turno' => '/turnos/%d', 'nivel' => '/niveis-academicos/%d',
            'turma' => '/turmas/%d', 'plano' => '/planos-curriculares/%d', 'aluno' => '/alunos/%d',
        ];

        foreach ($detalhes as $entidade => $modelo) {
            $this->actingAs($admin)
                ->get($this->urlDoTenant($this->tenant, sprintf($modelo, $a[$entidade]->id)))
                ->assertOk();
            $this->actingAs($admin)
                ->get($this->urlDoTenant($this->tenant, sprintf($modelo, $b[$entidade]->id)))
                ->assertNotFound();
        }

        // A matrícula não tem detalhe próprio: usa-se o histórico, aninhado no aluno.
        $historico = '/alunos/%d/matriculas/%d/historico';
        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, sprintf($historico, $a['aluno']->id, $a['matricula']->id)))
            ->assertOk();
        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, sprintf($historico, $b['aluno']->id, $b['matricula']->id)))
            ->assertNotFound();
    }

    public function test_as_oito_tabelas_com_estabelecimento_exigem_a_chave_composta(): void
    {
        $this->dadosDeA();
        $b = $this->dadosDeB();

        foreach (self::COM_ESTABELECIMENTO as $tabela) {
            // Uma linha válida de B, com o tenant trocado para A: o estabelecimento deixa de pertencer ao tenant.
            $linha = (array) DB::table($tabela)->where('tenant_id', $this->outro->id)->first();
            $this->assertNotEmpty($linha, "{$tabela}: não há linha de B para copiar.");
            $this->assertSame(Estabelecimento::withoutGlobalScopes()->where('tenant_id', $this->outro->id)->value('id'), $linha['estabelecimento_id']);

            unset($linha['id']);
            $linha['tenant_id'] = $this->tenant->id;

            // Afasta os valores das colunas únicas, para que só a chave composta possa recusar a linha.
            foreach (Schema::getIndexes($tabela) as $indice) {
                if (! $indice['unique'] || $indice['primary']) {
                    continue;
                }
                foreach ($indice['columns'] as $coluna) {
                    if (! in_array($coluna, ['tenant_id', 'estabelecimento_id'], true) && is_string($linha[$coluna] ?? null)) {
                        $linha[$coluna] .= '-copia';
                    }
                }
            }

            if ($tabela === 'alunos') {
                // dados_pessoa_id também é único: usa uma pessoa livre de A.
                $linha['dados_pessoa_id'] = DadosPessoa::create(['nome_completo' => 'Livre', 'numero_identificacao' => 'BI-LIVRE', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO])->id;
            }

            try {
                DB::table($tabela)->insert($linha);
                $this->fail("{$tabela}: aceitou tenant de A com estabelecimento de B.");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/foreign key/i', $e->getMessage(), "{$tabela}: recusado por outro motivo.");
            }
        }

        $this->assertNotEmpty($b);
    }
}
