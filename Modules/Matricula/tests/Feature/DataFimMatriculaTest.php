<?php

namespace Modules\Matricula\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * O1 (aprovado pelo dono em F3): ao terminar uma matrícula, a data de fim fica entre a data da matrícula e hoje
 * (dia da escola); Concluída aceita uma data futura até ao fim do ano lectivo. Uma data retroactiva anterior à
 * matrícula cancelaria em silêncio propinas de períodos já vencidos (§9): recusa-se com erro em data_fim.
 */
class DataFimMatriculaTest extends TestCase
{
    use RefreshDatabase;

    private ?Turma $turma = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Ano 2026/2027 (01/09/2026–31/07/2027), uma turma partilhada e uma matrícula Activa nova por chamada. */
    private function matricula(string $dataMatricula = '2026-09-01'): Matricula
    {
        $estabelecimento = $this->estabelecimentoDeTeste();

        if ($this->turma === null) {
            $ano = AnoLectivo::create([
                'estabelecimento_id' => $estabelecimento->id,
                'nome' => '2026/2027',
                'data_inicio' => '2026-09-01',
                'data_fim' => '2027-07-31',
                'estado' => EstadoAnoLectivo::ATIVO,
            ]);
            $nivel = NivelAcademico::create([
                'estabelecimento_id' => $estabelecimento->id,
                'codigo' => '1C',
                'nome' => 'Nível 1C',
                'ordem' => 1,
                'etapa_ensino' => 1,
            ]);
            $this->turma = Turma::create([
                'ano_lectivo_id' => $ano->id,
                'nivel_academico_id' => $nivel->id,
                'codigo' => 'T1',
                'nome' => 'Turma Teste',
                'estado' => 1,
            ]);
        }

        $pessoa = DadosPessoa::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => 'BI-' . uniqid(),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => 'AL-' . uniqid(),
        ]);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, null, $this->turma->nivel_academico_id);

        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $this->turma->id,
            'ano_lectivo_id' => $this->turma->ano_lectivo_id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => $dataMatricula,
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    private function terminar(Matricula $matricula, EstadoMatriculaEnum $estado, ?string $dataFim): Matricula
    {
        return app(AlterarEstadoMatriculaAction::class)->executar($matricula, $estado, null, $dataFim);
    }

    /** Recusa com erro em data_fim: nada muda (estado, data de fim, histórico) e o evento não sai. */
    private function assertRecusa(Matricula $matricula, EstadoMatriculaEnum $estado, string $dataFim, string $mensagem): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);
        $historicoAntes = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count();

        try {
            $this->terminar($matricula, $estado, $dataFim);
            $this->fail("Devia recusar {$dataFim} para {$estado->label()}.");
        } catch (ValidationException $e) {
            $this->assertSame(['data_fim' => [$mensagem]], $e->errors());
        }

        $matricula->refresh();
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->estado);
        $this->assertNull($matricula->data_fim);
        $this->assertSame($historicoAntes, MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count());
        Event::assertNotDispatched(MatriculaEstadoAlterado::class);
    }

    public function test_aceita_datas_entre_a_data_da_matricula_e_hoje(): void
    {
        $casos = [
            [EstadoMatriculaEnum::CANCELADA, '2026-09-01'],
            [EstadoMatriculaEnum::TRANSFERIDA, '2026-10-16'],
            [EstadoMatriculaEnum::CONCLUIDA, '2026-09-20'],
        ];

        foreach ($casos as [$estado, $data]) {
            $this->assertSame($data, $this->terminar($this->matricula(), $estado, $data)->data_fim->toDateString(), $estado->label());
        }

        // Sem data: hoje da escola (comportamento anterior).
        $this->assertSame('2026-10-16', $this->terminar($this->matricula(), EstadoMatriculaEnum::CANCELADA, null)->data_fim->toDateString());
    }

    public function test_recusa_uma_data_anterior_a_matricula(): void
    {
        $this->assertRecusa($this->matricula(), EstadoMatriculaEnum::CANCELADA, '2026-08-31', 'A data de fim não pode ser anterior à data da matrícula (01/09/2026).');
    }

    public function test_cancelada_e_transferida_nao_aceitam_uma_data_futura(): void
    {
        foreach ([EstadoMatriculaEnum::CANCELADA, EstadoMatriculaEnum::TRANSFERIDA] as $estado) {
            $this->assertRecusa($this->matricula(), $estado, '2026-10-17', 'A data de fim não pode ser posterior a hoje (16/10/2026).');
        }
    }

    public function test_concluida_aceita_uma_data_futura_ate_ao_fim_do_ano_lectivo(): void
    {
        $this->assertSame('2027-07-31', $this->terminar($this->matricula(), EstadoMatriculaEnum::CONCLUIDA, '2027-07-31')->data_fim->toDateString());

        $this->assertRecusa($this->matricula(), EstadoMatriculaEnum::CONCLUIDA, '2027-08-01', 'A data de fim de uma matrícula concluída não pode ser posterior ao fim do ano lectivo (31/07/2027).');
    }

    public function test_uma_matricula_que_ainda_nao_comecou_termina_hoje(): void
    {
        $this->assertSame('2026-10-16', $this->terminar($this->matricula('2026-11-02'), EstadoMatriculaEnum::CANCELADA, null)->data_fim->toDateString());

        $this->assertRecusa($this->matricula('2026-11-02'), EstadoMatriculaEnum::CANCELADA, '2026-10-15', 'A data de fim não pode ser anterior a hoje (16/10/2026).');
    }

    public function test_por_http_o_erro_chega_ao_campo_data_fim(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('segredo123')]);
        $staff->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $matricula = $this->matricula();

        $this->actingAs($staff)
            ->from('/matriculas')
            ->patch(route('matriculas.alterar-estado', [$matricula->aluno_id, $matricula->id]), [
                'estado' => EstadoMatriculaEnum::CANCELADA->value,
                'data_fim' => '2026-08-01',
            ])
            ->assertSessionHasErrors(['data_fim' => 'A data de fim não pode ser anterior à data da matrícula (01/09/2026).']);

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->refresh()->estado);
    }
}
