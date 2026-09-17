<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\MatriculaConsultaService;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class MatriculaConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    private function criarAluno(Estabelecimento $estabelecimento): Aluno
    {
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        return Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
    }

    private function criarTurma(Estabelecimento $estabelecimento, AnoLectivo $anoLectivo): Turma
    {
        $sufixo = uniqid();
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => "N{$sufixo}",
            'nome' => "Nível Teste {$sufixo}",
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
            'ordem' => 1,
        ]);

        return Turma::create([
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T' . $sufixo,
            'nome' => 'Turma Teste',
        ]);
    }

    private function matricular(Aluno $aluno, Turma $turma, AnoLectivo $anoLectivo, string $dataMatricula): Matricula
    {
        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => 'M' . uniqid(),
            'data_matricula' => $dataMatricula,
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    public function test_matricula_actual_devolve_a_matricula_do_ano_lectivo_activo(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $anoAnterior = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2025/2026', 'data_inicio' => '2025-09-01', 'data_fim' => '2026-07-31', 'estado' => EstadoAnoLectivo::ENCERRADO]);
        $aluno = $this->criarAluno($estabelecimento);
        $turmaAnterior = $this->criarTurma($estabelecimento, $anoAnterior);
        $turmaActual = $this->criarTurma($estabelecimento, $anoActivo);
        $this->matricular($aluno, $turmaAnterior, $anoAnterior, '2025-09-05');
        $matriculaActual = $this->matricular($aluno, $turmaActual, $anoActivo, '2026-09-05');

        $resultado = app(MatriculaConsultaService::class)->matriculaActual($aluno);

        $this->assertNotNull($resultado);
        $this->assertSame($matriculaActual->id, $resultado->id);
        $this->assertTrue($resultado->relationLoaded('turma'));
        $this->assertTrue($resultado->relationLoaded('anoLectivo'));
    }

    public function test_matricula_actual_carrega_o_turno_da_turma(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $turno = Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $aluno = $this->criarAluno($estabelecimento);
        $turma = $this->criarTurma($estabelecimento, $anoActivo);
        $turma->update(['turno_id' => $turno->id]);
        $this->matricular($aluno, $turma, $anoActivo, '2026-09-05');

        $resultado = app(MatriculaConsultaService::class)->matriculaActual($aluno);

        $this->assertTrue($resultado->turma->relationLoaded('turno'));
        $this->assertSame('Manhã', $resultado->turma->turno->nome);
    }

    public function test_matricula_actual_carrega_a_sala_activa_da_turma(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $sala = Sala::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'S1', 'nome' => 'Sala 1', 'tipo' => TipoSala::SALA_AULA->value]);
        $aluno = $this->criarAluno($estabelecimento);
        $turma = $this->criarTurma($estabelecimento, $anoActivo);
        $turma->turmaSalas()->create(['sala_id' => $sala->id, 'inicio' => '2026-09-01']);
        $this->matricular($aluno, $turma, $anoActivo, '2026-09-05');

        $resultado = app(MatriculaConsultaService::class)->matriculaActual($aluno);

        $this->assertTrue($resultado->turma->relationLoaded('turmaSalas'));
        $this->assertSame('Sala 1', $resultado->turma->turmaSalas->first()->sala->nome);
    }

    public function test_matricula_actual_devolve_null_quando_aluno_nao_tem_matricula_no_ano_lectivo_activo(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $anoActivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-09-01', 'data_fim' => '2027-07-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $anoAnterior = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2025/2026', 'data_inicio' => '2025-09-01', 'data_fim' => '2026-07-31', 'estado' => EstadoAnoLectivo::ENCERRADO]);
        $aluno = $this->criarAluno($estabelecimento);
        $turmaAnterior = $this->criarTurma($estabelecimento, $anoAnterior);
        $this->matricular($aluno, $turmaAnterior, $anoAnterior, '2025-09-05');

        $resultado = app(MatriculaConsultaService::class)->matriculaActual($aluno);

        $this->assertNull($resultado);
    }
}
