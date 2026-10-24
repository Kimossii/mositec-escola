<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use stdClass;
use Tests\TestCase;

/**
 * F3 (D11): a Matrícula consulta dependências registadas por outros módulos antes de mudar turma, ano lectivo
 * ou data — sem os conhecer. Aqui as dependências são de teste.
 */
class DependenciasDaMatriculaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Turma> */
    private array $turmas = [];

    private function matriculaActiva(): Matricula
    {
        $estabelecimento = $this->estabelecimentoDeTeste();
        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2026',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '1C',
            'nome' => 'Nível 1C',
            'ordem' => 1,
            'etapa_ensino' => 1,
        ]);

        foreach (['original', 'nova'] as $chave) {
            $this->turmas[$chave] = Turma::create([
                'ano_lectivo_id' => $ano->id,
                'nivel_academico_id' => $nivel->id,
                'codigo' => 'T' . random_int(1000, 9999),
                'nome' => "Turma {$chave}",
                'estado' => 1,
            ]);
        }

        $pessoa = DadosPessoa::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => 'BI' . random_int(100000, 999999),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-' . random_int(1000, 9999),
        ]);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, null, $nivel->id);

        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $this->turmas['original']->id,
            'ano_lectivo_id' => $ano->id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2026-02-10',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    private function dto(Matricula $matricula, string $turma, string $data): MatriculaDTO
    {
        return new MatriculaDTO(turmaId: $this->turmas[$turma]->id, anoLectivoId: $matricula->ano_lectivo_id, dataMatricula: $data, estado: null, observacoes: null);
    }

    /** Regista uma dependência de teste que regista as chamadas e bloqueia quando $motivo não é nulo. */
    private function dependencia(?string $motivo): object
    {
        $dependencia = new class ($motivo) implements DependenciasDaMatricula {
            /** @var list<array{0: int, 1: int, 2: int, 3: string, 4: int}> */
            public array $chamadas = [];

            public function __construct(private ?string $motivo)
            {
            }

            public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
            {
                // turma_id ainda é o actual: a decisão é tomada antes de gravar.
                $this->chamadas[] = [(int) $matricula->id, $turmaNovaId, $anoLectivoNovoId, $dataMatriculaNova, (int) $matricula->turma_id];

                return $this->motivo;
            }
        };

        $this->app->instance('dependencia.teste', $dependencia);
        $this->app->tag(['dependencia.teste'], DependenciasRegistadasDaMatricula::ETIQUETA);

        return $dependencia;
    }

    public function test_sem_bloqueio_actualiza_e_a_dependencia_ve_os_valores_actuais_e_os_novos(): void
    {
        $matricula = $this->matriculaActiva();
        $dependencia = $this->dependencia(null);

        $actualizada = app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'nova', '2026-02-20'));

        $this->assertSame($this->turmas['nova']->id, $actualizada->turma_id);
        $this->assertSame(
            [[$matricula->id, $this->turmas['nova']->id, $matricula->ano_lectivo_id, '2026-02-20', $this->turmas['original']->id]],
            $dependencia->chamadas,
        );
    }

    public function test_mudar_a_turma_bloqueado_da_erro_em_turma_e_nada_muda(): void
    {
        $matricula = $this->matriculaActiva();
        $this->dependencia('Esta matrícula tem dependências.');

        try {
            app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'nova', '2026-02-10'));
            $this->fail('Devia bloquear.');
        } catch (ValidationException $e) {
            $this->assertSame(['turma_id' => ['Esta matrícula tem dependências.']], $e->errors());
        }

        $this->assertSame($this->turmas['original']->id, $matricula->refresh()->turma_id);
    }

    public function test_mudar_so_a_data_bloqueado_da_erro_em_data_matricula(): void
    {
        $matricula = $this->matriculaActiva();
        $this->dependencia('A data não pode mudar.');

        try {
            app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'original', '2026-04-01'));
            $this->fail('Devia bloquear.');
        } catch (ValidationException $e) {
            $this->assertSame(['data_matricula' => ['A data não pode mudar.']], $e->errors());
        }

        $this->assertSame('2026-02-10', $matricula->refresh()->data_matricula->toDateString());
    }

    public function test_um_servico_etiquetado_que_nao_implementa_o_contrato_e_um_erro_de_configuracao(): void
    {
        $matricula = $this->matriculaActiva();
        $this->app->instance('dependencia.invalida', new stdClass());
        $this->app->tag(['dependencia.invalida'], DependenciasRegistadasDaMatricula::ETIQUETA);

        $this->expectException(LogicException::class);

        app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'nova', '2026-02-10'));
    }
}
