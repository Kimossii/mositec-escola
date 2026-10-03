<?php

namespace Tests\Concerns;

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
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\DadosPessoa;

trait PopulaDadosAcademicos
{
    /**
     * Um registo de cada entidade académica no tenant do contexto. `$m` marca
     * nomes e códigos (distintos entre tenants); corre-se dentro de `noTenant()` para B.
     */
    protected function popularDadosAcademicos(string $m): array
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
}
