<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class AlunoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    private function criarAluno(Estabelecimento $estabelecimento, string $nome, string $numeroIdentificacao, string $numeroMatricula): Aluno
    {
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => $numeroIdentificacao, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        return Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => $numeroMatricula]);
    }

    private function criarNivelAcademico(Estabelecimento $estabelecimento): NivelAcademico
    {
        $sufixo = uniqid();

        return NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => "N{$sufixo}",
            'nome' => "Nível Teste {$sufixo}",
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
            'ordem' => 1,
        ]);
    }

    private function criarTurma(Estabelecimento $estabelecimento, AnoLectivo $anoLectivo, ?Curso $curso = null, ?NivelAcademico $nivelAcademico = null): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $anoLectivo->id,
            'curso_id' => $curso?->id,
            'nivel_academico_id' => ($nivelAcademico ?? $this->criarNivelAcademico($estabelecimento))->id,
            'codigo' => 'T' . uniqid(),
            'nome' => 'Turma Teste',
        ]);
    }

    private function matricular(Aluno $aluno, Turma $turma, AnoLectivo $anoLectivo): Matricula
    {
        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => 'M' . uniqid(),
            'data_matricula' => now(),
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    public function test_ordena_por_numero_matricula_descendente_para_o_ultimo_registado_aparecer_primeiro(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $this->criarAluno($estabelecimento, 'Ana Silva', 'BI0001', '2026-0001');
        $this->criarAluno($estabelecimento, 'Bruno Costa', 'BI0002', '2026-0002');
        $this->criarAluno($estabelecimento, 'Carla Dias', 'BI0003', '2026-0003');

        $resultado = (new AlunoConsultaService())->listar();

        $this->assertSame(
            ['2026-0003', '2026-0002', '2026-0001'],
            $resultado->pluck('numero_matricula')->all(),
        );
    }

    public function test_lista_apenas_alunos_do_estabelecimento_actual(): void
    {
        $actual = Estabelecimento::create(['nome' => 'Escola Actual', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $outra = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);

        $pessoa1 = DadosPessoa::create(['nome_completo' => 'Ana', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $pessoa2 = DadosPessoa::create(['nome_completo' => 'Bruno', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        Aluno::create(['estabelecimento_id' => $actual->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);
        Aluno::create(['estabelecimento_id' => $outra->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);

        $alunos = (new AlunoConsultaService())->listar();

        $this->assertCount(1, $alunos);
        $this->assertSame('2026-0001', $alunos->first()->numero_matricula);
    }

    public function test_pesquisa_filtra_por_nome_numero_matricula_ou_numero_identificacao(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $this->criarAluno($estabelecimento, 'Ana Silva', 'BI0001', '2026-0001');
        $this->criarAluno($estabelecimento, 'Bruno Costa', 'BI0002', '2026-0002');

        $porNome = (new AlunoConsultaService())->listar(['pesquisa' => 'Ana Silva']);
        $porMatricula = (new AlunoConsultaService())->listar(['pesquisa' => '2026-0002']);
        $porIdentificacao = (new AlunoConsultaService())->listar(['pesquisa' => 'BI0001']);

        $this->assertCount(1, $porNome);
        $this->assertSame('Ana Silva', $porNome->first()->dadosPessoa->nome_completo);
        $this->assertCount(1, $porMatricula);
        $this->assertSame('Bruno Costa', $porMatricula->first()->dadosPessoa->nome_completo);
        $this->assertCount(1, $porIdentificacao);
        $this->assertSame('Ana Silva', $porIdentificacao->first()->dadosPessoa->nome_completo);
    }

    public function test_filtra_por_ano_lectivo(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $ano2026 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $ano2025 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2025/2026', 'data_inicio' => '2025-09-01', 'data_fim' => '2026-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $turma2026 = $this->criarTurma($estabelecimento, $ano2026);
        $turma2025 = $this->criarTurma($estabelecimento, $ano2025);

        $alunoDe2026 = $this->criarAluno($estabelecimento, 'Ana Silva', 'BI0001', '2026-0001');
        $alunoDe2025 = $this->criarAluno($estabelecimento, 'Bruno Costa', 'BI0002', '2025-0001');
        $this->matricular($alunoDe2026, $turma2026, $ano2026);
        $this->matricular($alunoDe2025, $turma2025, $ano2025);

        $resultado = (new AlunoConsultaService())->listar(['ano_lectivo_id' => $ano2026->id]);

        $this->assertCount(1, $resultado);
        $this->assertSame('Ana Silva', $resultado->first()->dadosPessoa->nome_completo);
    }

    public function test_filtra_por_turma(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $turmaA = $this->criarTurma($estabelecimento, $ano);
        $turmaB = $this->criarTurma($estabelecimento, $ano);

        $alunoA = $this->criarAluno($estabelecimento, 'Ana Silva', 'BI0001', '2026-0001');
        $alunoB = $this->criarAluno($estabelecimento, 'Bruno Costa', 'BI0002', '2026-0002');
        $this->matricular($alunoA, $turmaA, $ano);
        $this->matricular($alunoB, $turmaB, $ano);

        $resultado = (new AlunoConsultaService())->listar(['turma_id' => $turmaA->id]);

        $this->assertCount(1, $resultado);
        $this->assertSame('Ana Silva', $resultado->first()->dadosPessoa->nome_completo);
    }

    public function test_filtra_por_curso(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $cursoA = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $cursoB = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CTB', 'nome' => 'Contabilidade']);
        $turmaA = $this->criarTurma($estabelecimento, $ano, $cursoA);
        $turmaB = $this->criarTurma($estabelecimento, $ano, $cursoB);

        $alunoA = $this->criarAluno($estabelecimento, 'Ana Silva', 'BI0001', '2026-0001');
        $alunoB = $this->criarAluno($estabelecimento, 'Bruno Costa', 'BI0002', '2026-0002');
        $this->matricular($alunoA, $turmaA, $ano);
        $this->matricular($alunoB, $turmaB, $ano);

        $resultado = (new AlunoConsultaService())->listar(['curso_id' => $cursoA->id]);

        $this->assertCount(1, $resultado);
        $this->assertSame('Ana Silva', $resultado->first()->dadosPessoa->nome_completo);
    }

    public function test_filtra_por_nivel_academico(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivelA = $this->criarNivelAcademico($estabelecimento);
        $nivelB = $this->criarNivelAcademico($estabelecimento);
        $turmaA = $this->criarTurma($estabelecimento, $ano, null, $nivelA);
        $turmaB = $this->criarTurma($estabelecimento, $ano, null, $nivelB);

        $alunoA = $this->criarAluno($estabelecimento, 'Ana Silva', 'BI0001', '2026-0001');
        $alunoB = $this->criarAluno($estabelecimento, 'Bruno Costa', 'BI0002', '2026-0002');
        $this->matricular($alunoA, $turmaA, $ano);
        $this->matricular($alunoB, $turmaB, $ano);

        $resultado = (new AlunoConsultaService())->listar(['nivel_academico_id' => $nivelA->id]);

        $this->assertCount(1, $resultado);
        $this->assertSame('Ana Silva', $resultado->first()->dadosPessoa->nome_completo);
    }

    public function test_combina_ano_lectivo_e_turma_com_logica_e(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $ano2026 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $ano2025 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2025/2026', 'data_inicio' => '2025-09-01', 'data_fim' => '2026-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $turma = $this->criarTurma($estabelecimento, $ano2026);
        $turmaOutroAno = $this->criarTurma($estabelecimento, $ano2025);

        // Mesmo aluno, matriculado na mesma "turma" lógica em dois anos lectivos diferentes.
        $aluno = $this->criarAluno($estabelecimento, 'Ana Silva', 'BI0001', '2026-0001');
        $this->matricular($aluno, $turma, $ano2026);
        $this->matricular($aluno, $turmaOutroAno, $ano2025);

        $combinado = (new AlunoConsultaService())->listar(['ano_lectivo_id' => $ano2026->id, 'turma_id' => $turma->id]);
        $anoErrado = (new AlunoConsultaService())->listar(['ano_lectivo_id' => $ano2025->id, 'turma_id' => $turma->id]);

        $this->assertCount(1, $combinado, 'deve encontrar o aluno quando ano lectivo e turma correspondem à mesma matrícula');
        $this->assertCount(0, $anoErrado, 'não deve encontrar quando o ano lectivo não corresponde à matrícula dessa turma');
    }

    public function test_devolve_resultados_paginados(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        for ($i = 1; $i <= 15; $i++) {
            $this->criarAluno($estabelecimento, "Aluno {$i}", "BI{$i}", sprintf('2026-%04d', $i));
        }

        $pagina1 = (new AlunoConsultaService())->listar([], porPagina: 10);

        $this->assertSame(10, $pagina1->count());
        $this->assertSame(15, $pagina1->total());
        $this->assertSame(2, $pagina1->lastPage());
    }

    public function test_anos_lectivos_disponiveis_devolve_apenas_do_estabelecimento_actual(): void
    {
        $actual = $this->criarEstabelecimento();
        $outra = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
        AnoLectivo::create(['estabelecimento_id' => $actual->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        AnoLectivo::create(['estabelecimento_id' => $outra->id, 'nome' => '2026/2027 (outra)', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);

        $resultado = (new AlunoConsultaService())->anosLectivosDisponiveis();

        $this->assertCount(1, $resultado);
        $this->assertSame('2026/2027', $resultado->first()->nome);
    }

    public function test_cursos_disponiveis_devolve_apenas_activos_do_estabelecimento_actual(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática', 'estado' => Estado::ATIVO->value]);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CTB', 'nome' => 'Contabilidade', 'estado' => Estado::INATIVO->value]);

        $resultado = (new AlunoConsultaService())->cursosDisponiveis();

        $this->assertCount(1, $resultado);
        $this->assertSame('Informática', $resultado->first()->nome);
    }

    public function test_niveis_academicos_disponiveis_devolve_apenas_activos_do_estabelecimento_actual(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $activo = $this->criarNivelAcademico($estabelecimento);
        $inactivo = $this->criarNivelAcademico($estabelecimento);
        $inactivo->update(['estado' => Estado::INATIVO->value]);

        $resultado = (new AlunoConsultaService())->niveisAcademicosDisponiveis();

        $this->assertCount(1, $resultado);
        $this->assertSame($activo->id, $resultado->first()->id);
    }

    public function test_turmas_disponiveis_devolve_apenas_do_estabelecimento_actual(): void
    {
        $actual = $this->criarEstabelecimento();
        $outra = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
        $anoActual = AnoLectivo::create(['estabelecimento_id' => $actual->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $anoOutra = AnoLectivo::create(['estabelecimento_id' => $outra->id, 'nome' => '2026/2027 (outra)', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $turmaActual = $this->criarTurma($actual, $anoActual);
        $this->criarTurma($outra, $anoOutra);

        $resultado = (new AlunoConsultaService())->turmasDisponiveis();

        $this->assertCount(1, $resultado);
        $this->assertSame($turmaActual->id, $resultado->first()->id);
    }
}
