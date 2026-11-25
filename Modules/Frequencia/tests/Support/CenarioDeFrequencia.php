<?php

namespace Modules\Frequencia\Tests\Support;

use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Frequencia\Enums\EstadoPresenca;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Models\Registo;
use Modules\Horario\Tests\Support\CenarioDeHorario;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;

/**
 * Cenário base da frequência: o do horário (turma 10A, turno, tempos, sala, docente titular das duas disciplinas)
 * mais uma entrada de segunda-feira no 1.º tempo (Matemática, vigente desde 2026-01-01) e três alunos matriculados
 * e inscritos em Matemática. Requer o contexto de tenant de `Tests\Concerns\ComTenantDeTeste`.
 */
trait CenarioDeFrequencia
{
    use CenarioDeHorario;

    private static int $alunosCriados = 0;

    /** @return array<string, mixed> o cenário do horário + `entrada` e `alunos` (list<Matricula>) */
    protected function cenarioDeFrequencia(): array
    {
        $c = $this->cenarioDeHorario();
        $c['entrada'] = $this->criarEntrada($c, ['vigencia_inicio' => '2026-01-01']);
        $c['alunos'] = [
            $this->matricularAluno($c, 'Ana Aluna'),
            $this->matricularAluno($c, 'Bruno Aluno'),
            $this->matricularAluno($c, 'Carla Aluna'),
        ];

        return $c;
    }

    /** Aluno com matrícula `ACTIVA` na turma e inscrição em Matemática (ou na disciplina indicada). */
    protected function matricularAluno(array $c, string $nome, string $dataMatricula = '2026-02-01', ?int $disciplinaId = null, EstadoMatriculaEnum $estado = EstadoMatriculaEnum::ACTIVA): Matricula
    {
        $n = ++self::$alunosCriados;
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI-ALU-' . $n, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => 'M' . $n]);
        $matricula = Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $c['turma']->id, 'ano_lectivo_id' => $c['ano']->id,
            'numero_registo_matricula' => 'R' . $n, 'data_matricula' => $dataMatricula, 'estado' => $estado->value,
        ]);
        InscricaoDisciplina::create([
            'matricula_id' => $matricula->id,
            'plano_curricular_disciplina_id' => PlanoCurricularDisciplina::where('plano_curricular_id', $c['plano']->id)
                ->where('disciplina_id', $disciplinaId ?? $c['disciplina']->id)->firstOrFail()->id,
            'data_inscricao' => $dataMatricula, 'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA->value,
        ]);

        return $matricula;
    }

    /** Conta de professor ligada (pela pessoa) ao docente indicado. */
    protected function utilizadorDoDocente(Docente $docente): User
    {
        $utilizador = $this->criarProfessor('doc' . $docente->id . '@example.com');
        $utilizador->update(['dados_pessoa_id' => $docente->dados_pessoa_id]);

        return $utilizador->fresh();
    }

    /** Conta com o perfil Funcionário (tem `frequencia.corrigir-fora-prazo`). */
    protected function funcionario(): User
    {
        if (! Role::where('nome', Perfil::FUNCIONARIO->value)->exists()) {
            $this->seed(PermissaoDatabaseSeeder::class);
        }

        $u = User::create(['name' => 'Secretaria', 'email' => 'secretaria@example.com', 'password' => Hash::make('segredo123')]);
        $u->roles()->attach(Role::where('nome', Perfil::FUNCIONARIO->value)->firstOrFail()->id);

        return $u;
    }

    /** Aula guardada com um registo por aluno do cenário (estados por ordem; os que faltarem ficam presentes). */
    protected function aulaComRegistos(array $c, string $data, array $estados = [], array $o = []): Aula
    {
        $aula = Aula::create(array_merge([
            'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'tipo' => TipoAula::TEMPO, 'data' => $data,
            'horario_id' => $c['tempo1']->id, 'disciplina_id' => $c['disciplina']->id, 'docente_id' => $c['docente']->id,
        ], $o));

        foreach ($c['alunos'] as $i => $matricula) {
            Registo::create(['aula_id' => $aula->id, 'matricula_id' => $matricula->id, 'estado' => $estados[$i] ?? EstadoPresenca::PRESENTE]);
        }

        return $aula;
    }
}
