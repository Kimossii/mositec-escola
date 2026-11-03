<?php

namespace Modules\Avaliacao\Tests\Support;

use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Cenário base da avaliação no tenant do contexto (corre-se dentro de `noTenant()` para outro tenant):
 * ano 2026 com 3 trimestres, uma classe, uma turma, uma disciplina no plano e um aluno inscrito nela.
 */
trait CenarioDeAvaliacao
{
    private static int $alunosDeAvaliacao = 0;

    /**
     * @return array{ano: AnoLectivo, periodos: array<int, Periodo>, nivel: NivelAcademico, turma: Turma, disciplina: Disciplina, planoDisciplina: PlanoCurricularDisciplina}
     */
    protected function cenarioDeAvaliacao(string $codigoNivel = 'PRI_3', string $marca = 'A'): array
    {
        $estabelecimentoId = Estabelecimento::current()->id;

        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimentoId, 'nome' => "2026{$marca}", 'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);

        $periodos = [];
        foreach ([1 => ['2026-01-01', '2026-04-30'], 2 => ['2026-05-01', '2026-08-31'], 3 => ['2026-09-01', '2026-12-31']] as $n => [$inicio, $fim]) {
            $periodos[$n] = Periodo::create([
                'ano_lectivo_id' => $ano->id, 'nome' => "{$n}.º Trimestre", 'tipo' => TipoPeriodo::TRIMESTRE,
                'numero' => $n, 'data_inicio' => $inicio, 'data_fim' => $fim,
            ]);
        }

        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => "CU{$marca}", 'nome' => "Curso{$marca}"]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimentoId, 'codigo' => $codigoNivel, 'nome' => "Classe {$codigoNivel}",
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO, 'ordem' => 1,
        ]);
        $turma = Turma::create([
            'ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id,
            'codigo' => "TU{$marca}", 'nome' => "Turma{$marca}",
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => "MAT{$marca}", 'nome' => "Matemática{$marca}"]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimentoId, 'curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id,
            'codigo' => "PL{$marca}", 'nome' => "Plano{$marca}",
        ]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        return compact('ano', 'periodos', 'nivel', 'turma', 'disciplina', 'planoDisciplina');
    }

    /** Aluno matriculado na turma do cenário e inscrito na sua disciplina. */
    protected function inscreverAluno(array $cenario, string $nome = 'Aluno'): InscricaoDisciplina
    {
        $n = ++self::$alunosDeAvaliacao;

        $pessoa = DadosPessoa::create(['nome_completo' => "{$nome} {$n}", 'numero_identificacao' => "BI-AV-{$n}", 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => "2026-AV-{$n}",
        ]);
        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $cenario['turma']->id, 'ano_lectivo_id' => $cenario['ano']->id,
            'numero_registo_matricula' => "REG-AV-{$n}", 'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        return InscricaoDisciplina::create([
            'matricula_id' => $matricula->id, 'plano_curricular_disciplina_id' => $cenario['planoDisciplina']->id,
            'data_inscricao' => '2026-02-01', 'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA,
        ]);
    }

    /** Utilizador do tenant com o perfil dado (semeia os perfis se ainda não existirem). */
    protected function criarUtilizadorComPerfil(Perfil $perfil, string $email): User
    {
        if (! Role::where('nome', $perfil->value)->exists()) {
            $this->seed(PermissaoDatabaseSeeder::class);
        }

        $user = User::create(['name' => $perfil->name.' '.$email, 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->firstOrFail()->id);

        return $user;
    }

    protected function criarDocenteDeAvaliacao(): Docente
    {
        $n = ++self::$alunosDeAvaliacao;
        $pessoa = DadosPessoa::create(['nome_completo' => "Docente {$n}", 'numero_identificacao' => "BI-DOC-AV-{$n}", 'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR]);

        return Docente::create(['estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoa->id]);
    }

    /** Professor (com conta) atribuído como titular à turma e disciplina do cenário. */
    protected function professorAtribuido(array $cenario, string $email = 'prof@example.com'): User
    {
        $docente = $this->criarDocenteDeAvaliacao();
        $professor = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, $email);
        $professor->update(['dados_pessoa_id' => $docente->dados_pessoa_id]);

        AtribuicaoDocente::create([
            'ano_lectivo_id' => $cenario['ano']->id, 'turma_id' => $cenario['turma']->id, 'disciplina_id' => $cenario['disciplina']->id,
            'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR, 'data_inicio' => '2026-01-01',
        ]);

        return $professor;
    }
}
