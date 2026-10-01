<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Tenant\Models\Tenant;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Matriz transversal dos módulos académicos: dois tenants populados em todas as
 * entidades; cada listagem mostra só os dados do domínio e o id do outro tenant dá 404.
 */
class AcademicoIsolamentoTest extends TestCase
{
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

    /**
     * Um registo de cada entidade académica no tenant do contexto. `$m` marca
     * nomes e códigos (distintos entre tenants); corre-se dentro de `noTenant()` para B.
     */
    private function popular(string $m): array
    {
        $estabelecimentoId = Estabelecimento::current()->id;

        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimentoId, 'nome' => "Ano{$m}", 'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $periodo = Periodo::create([
            'ano_lectivo_id' => $ano->id, 'nome' => "Periodo{$m}", 'tipo' => TipoPeriodo::TRIMESTRE,
            'numero' => 1, 'data_inicio' => '2026-01-01', 'data_fim' => '2026-04-01',
        ]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => "CU{$m}", 'nome' => "Curso{$m}"]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => "DI{$m}", 'nome' => "Disciplina{$m}"]);
        $sala = Sala::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => "SA{$m}", 'nome' => "Sala{$m}", 'tipo' => TipoSala::SALA_AULA->value]);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimentoId, 'nome' => "Turno{$m}"]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimentoId, 'codigo' => "NI{$m}", 'nome' => "Nivel{$m}",
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1,
        ]);
        $turma = Turma::create(['ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'turno_id' => $turno->id, 'codigo' => "TU{$m}", 'nome' => "Turma{$m}"]);

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoId, 'curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id, 'codigo' => "PL{$m}", 'nome' => "Plano{$m}"]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
        PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $pessoa = DadosPessoa::create(['nome_completo' => "Aluno{$m}", 'numero_identificacao' => "BI-{$m}", 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimentoId, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => "2026-{$m}"]);
        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $ano->id,
            'numero_registo_matricula' => "REG-{$m}", 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::PENDENTE->value,
        ]);

        return compact('ano', 'periodo', 'curso', 'disciplina', 'sala', 'turno', 'nivel', 'turma', 'plano', 'aluno', 'matricula');
    }

    private function dadosDeA(): array
    {
        return $this->popular('AAA');
    }

    private function dadosDeB(): array
    {
        return $this->noTenant($this->outro, fn () => $this->popular('BBB'));
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
