<?php

namespace Modules\Docente\Tests\Support;


use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;

/**
 * Cenário base das atribuições docentes, no tenant do contexto (corre-se dentro de `noTenant()` para
 * criar o de outro tenant). Requer o contexto de tenant de `Tests\Concerns\ComTenantDeTeste`.
 */
trait CenarioDeDocencia
{
    /**
     * Ano lectivo 2026 (activo), turma com curso, e um plano curricular desse curso/nível associado ao ano
     * com duas disciplinas.
     *
     * @return array{ano: AnoLectivo, turma: Turma, disciplina: Disciplina, outraDisciplina: Disciplina, plano: PlanoCurricular}
     */
    protected function cenarioDeDocencia(): array
    {
        $estabelecimentoId = Estabelecimento::current()->id;

        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimentoId, 'nome' => '2026', 'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'CUR', 'nome' => 'Curso']);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimentoId, 'codigo' => '10C', 'nome' => '10ª Classe',
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO, 'ordem' => 1,
        ]);
        $turma = Turma::create([
            'ano_lectivo_id' => $ano->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id,
            'codigo' => 'T10A', 'nome' => 'Turma 10A',
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $outraDisciplina = Disciplina::create(['estabelecimento_id' => $estabelecimentoId, 'codigo' => 'POR', 'nome' => 'Português']);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimentoId, 'curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id,
            'codigo' => 'PL10', 'nome' => 'Plano 10ª',
        ]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
        PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);
        PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $outraDisciplina->id]);

        return compact('ano', 'turma', 'disciplina', 'outraDisciplina', 'plano');
    }

    /** Utilizador do tenant com o perfil PROFESSOR. */
    protected function criarProfessor(string $email = 'prof@example.com'): User
    {
        if (! Role::where('nome', Perfil::PROFESSOR->value)->exists()) {
            $this->seed(PermissaoDatabaseSeeder::class);
        }

        $professor = User::create(['name' => 'Professor ' . $email, 'email' => $email, 'password' => Hash::make('segredo123')]);
        $professor->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->firstOrFail()->id);

        return $professor;
    }
}
