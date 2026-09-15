<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AlterarEstadoAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlterarEstadoAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
    }

    public function test_encerra_o_ano_lectivo_activo(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2025/2026',
            dataInicio: '2025-09-01',
            dataFim: '2026-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $encerrado = (app(AlterarEstadoAnoLectivoAction::class))->alterar($anoLectivo, EstadoAnoLectivo::ENCERRADO);

        $this->assertSame(EstadoAnoLectivo::ENCERRADO, $encerrado->estado);
    }

    public function test_bloqueia_activar_planeado_quando_outro_ja_esta_activo(): void
    {
        (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $planeado = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2027/2028',
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->expectException(ValidationException::class);

        (app(AlterarEstadoAnoLectivoAction::class))->alterar($planeado, EstadoAnoLectivo::ATIVO);
    }

    public function test_activa_planeado_depois_de_encerrar_o_ativo_anterior(): void
    {
        $antigo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $planeado = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2027/2028',
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        (app(AlterarEstadoAnoLectivoAction::class))->alterar($antigo, EstadoAnoLectivo::ENCERRADO);
        $novoAtivo = (app(AlterarEstadoAnoLectivoAction::class))->alterar($planeado, EstadoAnoLectivo::ATIVO);

        $this->assertSame(EstadoAnoLectivo::ATIVO, $novoAtivo->estado);
    }

    private function criarMatriculaNoAnoLectivo(AnoLectivo $anoLectivo, EstadoMatriculaEnum $estado, int $sufixo): Matricula
    {
        $estabelecimento = Estabelecimento::where('is_active', true)->first();
        $nivel = NivelAcademico::firstOrCreate(
            ['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C'],
            ['nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1],
        );
        $turma = Turma::firstOrCreate(
            ['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1'],
            ['nome' => 'Turma 1'],
        );
        $pessoa = DadosPessoal::create(['nome_completo' => "Aluno {$sufixo}", 'numero_identificacao' => "BI{$sufixo}", 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => "2026-{$sufixo}"]);

        return Matricula::create([
            'aluno_id' => $aluno->id, 'turma_id' => $turma->id, 'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => "2026-{$sufixo}", 'data_matricula' => '2026-02-01', 'estado' => $estado->value,
        ]);
    }

    public function test_bloqueia_encerrar_com_matriculas_por_resolver_sem_confirmacao(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026', dataInicio: '2026-01-01', dataFim: '2026-12-31', estado: EstadoAnoLectivo::ATIVO,
        ));
        $activa = $this->criarMatriculaNoAnoLectivo($anoLectivo, EstadoMatriculaEnum::ACTIVA, 1);
        $pendente = $this->criarMatriculaNoAnoLectivo($anoLectivo, EstadoMatriculaEnum::PENDENTE, 2);

        $this->expectException(ValidationException::class);

        try {
            app(AlterarEstadoAnoLectivoAction::class)->alterar($anoLectivo, EstadoAnoLectivo::ENCERRADO);
        } finally {
            $this->assertSame(EstadoAnoLectivo::ATIVO, $anoLectivo->fresh()->estado);
            $this->assertSame(EstadoMatriculaEnum::ACTIVA, $activa->fresh()->estado);
            $this->assertSame(EstadoMatriculaEnum::PENDENTE, $pendente->fresh()->estado);
        }
    }

    public function test_encerra_e_resolve_matriculas_quando_confirmado(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026', dataInicio: '2026-01-01', dataFim: '2026-12-31', estado: EstadoAnoLectivo::ATIVO,
        ));
        $activa = $this->criarMatriculaNoAnoLectivo($anoLectivo, EstadoMatriculaEnum::ACTIVA, 1);
        $pendente = $this->criarMatriculaNoAnoLectivo($anoLectivo, EstadoMatriculaEnum::PENDENTE, 2);

        $encerrado = app(AlterarEstadoAnoLectivoAction::class)->alterar(
            $anoLectivo,
            EstadoAnoLectivo::ENCERRADO,
            confirmarEncerramentoMatriculas: true,
        );

        $this->assertSame(EstadoAnoLectivo::ENCERRADO, $encerrado->estado);
        $this->assertSame(EstadoMatriculaEnum::CONCLUIDA, $activa->fresh()->estado);
        $this->assertSame(EstadoMatriculaEnum::CANCELADA, $pendente->fresh()->estado);
    }
}
