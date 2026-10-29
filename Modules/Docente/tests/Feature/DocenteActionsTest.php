<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Models\Aluno;
use Modules\Docente\Actions\AlterarEstadoDocenteAction;
use Modules\Docente\Actions\AtualizarDocenteAction;
use Modules\Docente\Actions\CriarDocenteAction;
use Modules\Docente\Actions\EliminarDocenteAction;
use Modules\Docente\Actions\GarantirDocenteDeUtilizadorAction;
use Modules\Docente\DTO\DocenteDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocenteActionsTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private function dto(array $sobre = []): DocenteDTO
    {
        return new DocenteDTO(...array_merge([
            'nomeCompleto' => 'Ana Docente',
            'numeroIdentificacao' => '004455AA001',
            'email' => null,
            'telefone' => null,
            'dataNascimento' => null,
            'sexo' => 2,
        ], $sobre));
    }

    public function test_criar_cria_pessoa_professor_e_docente_sem_conta_nem_email(): void
    {
        $docente = app(CriarDocenteAction::class)->executar($this->dto());

        $this->assertSame(1, $docente->estado);
        $this->assertSame(Estabelecimento::current()->id, (int) $docente->estabelecimento_id);
        $this->assertSame(DadosPessoa::TIPO_PROFESSOR, (int) $docente->dadosPessoa->tipo_pessoa);
        $this->assertSame('Ana Docente', $docente->dadosPessoa->nome_completo);
        $this->assertNull($docente->dadosPessoa->email);
        $this->assertFalse($docente->temAcesso());
        $this->assertSame(0, User::query()->count());
    }

    public function test_criar_com_bi_ja_existente_e_rejeitado_pela_base(): void
    {
        app(CriarDocenteAction::class)->executar($this->dto());

        $this->expectException(QueryException::class);

        app(CriarDocenteAction::class)->executar($this->dto());
    }

    public function test_atualizar_altera_os_dados_pessoais(): void
    {
        $docente = app(CriarDocenteAction::class)->executar($this->dto());

        $docente = app(AtualizarDocenteAction::class)->executar($docente, $this->dto([
            'nomeCompleto' => 'Ana Maria', 'email' => 'ana@example.com', 'telefone' => '923000000', 'dataNascimento' => '1990-05-04', 'sexo' => 2,
        ]));

        $this->assertSame('Ana Maria', $docente->dadosPessoa->nome_completo);
        $this->assertSame('ana@example.com', $docente->dadosPessoa->email);
        $this->assertSame('923000000', $docente->dadosPessoa->telefone);
        $this->assertSame('1990-05-04', $docente->dadosPessoa->data_nascimento->toDateString());
    }

    public function test_alterar_estado_desactiva_e_activa_com_descricao(): void
    {
        $docente = app(CriarDocenteAction::class)->executar($this->dto());

        $docente = app(AlterarEstadoDocenteAction::class)->executar($docente, 0);
        $this->assertSame(0, $docente->estado);
        $this->assertSame('Inativo', $docente->estado_descricao);

        $docente = app(AlterarEstadoDocenteAction::class)->executar($docente, 1);
        $this->assertSame(1, $docente->estado);
        $this->assertSame('Ativo', $docente->estado_descricao);
    }

    public function test_eliminar_sem_atribuicoes_apaga_docente_e_pessoa(): void
    {
        $docente = app(CriarDocenteAction::class)->executar($this->dto());
        $pessoaId = $docente->dados_pessoa_id;

        app(EliminarDocenteAction::class)->executar($docente);

        $this->assertNull(Docente::find($docente->id));
        $this->assertNull(DadosPessoa::find($pessoaId));
    }

    public function test_eliminar_docente_sem_conta_mantem_a_pessoa_se_for_tambem_de_um_aluno(): void
    {
        $docente = $this->criarDocente('Também aluno');
        $pessoaId = $docente->dados_pessoa_id;
        Aluno::create(['estabelecimento_id' => Estabelecimento::current()->id, 'dados_pessoa_id' => $pessoaId, 'numero_matricula' => '2026-9001']);

        app(EliminarDocenteAction::class)->executar($docente);

        $this->assertNull(Docente::find($docente->id));
        $this->assertNotNull(DadosPessoa::find($pessoaId));
    }

    public function test_eliminar_docente_sem_conta_mantem_a_pessoa_e_os_documentos(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $docente = $this->criarDocente('Com documentos');
        $pessoaId = $docente->dados_pessoa_id;
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoaId,
            'tipo_documento_id' => TipoDocumento::where('slug', 'bi')->firstOrFail()->id,
            'nome_original' => 'bi.pdf', 'caminho' => 'x', 'mime_type' => 'application/pdf', 'tamanho' => 10,
        ]);

        app(EliminarDocenteAction::class)->executar($docente);

        $this->assertNull(Docente::find($docente->id));
        $this->assertNotNull(DadosPessoa::find($pessoaId));
        $this->assertNotNull(DocumentoPessoa::find($documento->id));
    }

    public function test_eliminar_docente_sem_conta_aluno_nem_documentos_apaga_a_pessoa(): void
    {
        $docente = $this->criarDocente('Isolado');
        $pessoaId = $docente->dados_pessoa_id;

        app(EliminarDocenteAction::class)->executar($docente);

        $this->assertNull(DadosPessoa::find($pessoaId));
    }

    public function test_eliminar_com_atribuicao_terminada_ou_eliminada_e_bloqueado(): void
    {
        $c = $this->cenarioDeDocencia();

        foreach (['terminada', 'eliminada'] as $i => $tipo) {
            $docente = $this->criarDocente('Docente ' . $tipo);
            $a = AtribuicaoDocente::create([
                'ano_lectivo_id' => $c['ano']->id, 'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id,
                'docente_id' => $docente->id, 'papel' => PapelDocente::TITULAR,
                'data_inicio' => '2026-01-10', 'data_fim' => $tipo === 'terminada' ? '2026-02-10' : null,
            ]);
            if ($tipo === 'eliminada') {
                $a->delete();
            }

            try {
                app(EliminarDocenteAction::class)->executar($docente);
                $this->fail('Devia ter sido bloqueado: ' . $tipo);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('docente', $e->errors());
                $this->assertStringContainsString('atribuições', $e->errors()['docente'][0]);
            }

            $this->assertNotNull(Docente::find($docente->id));
        }
    }

    public function test_garantir_cria_docente_e_pessoa_para_utilizador_sem_pessoa_e_e_idempotente(): void
    {
        $user = $this->criarProfessor('prof1@example.com');
        $this->assertNull($user->dados_pessoa_id);

        $acao = app(GarantirDocenteDeUtilizadorAction::class);
        $docente = $acao->executar($user);
        $outra = $acao->executar($user->fresh());

        $this->assertSame($docente->id, $outra->id);
        $this->assertSame(1, Docente::query()->count());
        $user->refresh();
        $this->assertSame($docente->dados_pessoa_id, (int) $user->dados_pessoa_id);
        $this->assertSame(DadosPessoa::TIPO_PROFESSOR, (int) $docente->dadosPessoa->tipo_pessoa);
        $this->assertSame($user->name, $docente->dadosPessoa->nome_completo);
        $this->assertSame('prof1@example.com', $docente->dadosPessoa->email);
        $this->assertTrue($docente->temAcesso());
    }

    public function test_garantir_reutiliza_a_pessoa_do_utilizador_e_o_docente_existente(): void
    {
        $existente = $this->criarDocente('Já docente', comAcesso: true);
        $user = User::query()->where('dados_pessoa_id', $existente->dados_pessoa_id)->firstOrFail();

        $docente = app(GarantirDocenteDeUtilizadorAction::class)->executar($user);
        $this->assertSame($existente->id, $docente->id);

        // Utilizador com pessoa mas sem docente: cria o docente sobre essa pessoa.
        $pessoa = DadosPessoa::create(['nome_completo' => 'Pessoa X', 'numero_identificacao' => 'BI-X', 'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR]);
        $user2 = User::create(['name' => 'Pessoa X', 'email' => 'x@example.com', 'password' => Hash::make('x'), 'dados_pessoa_id' => $pessoa->id]);
        $novo = app(GarantirDocenteDeUtilizadorAction::class)->executar($user2);

        $this->assertSame($pessoa->id, (int) $novo->dados_pessoa_id);
        $this->assertSame(2, Docente::query()->count());
    }

    public function test_garantir_docente_de_conta_inactiva_fica_inactivo_e_de_activa_fica_activo(): void
    {
        $inactivo = $this->criarProfessor('inactivo@example.com');
        $inactivo->update(['estado' => 0]);
        $activo = $this->criarProfessor('activo@example.com');

        $acao = app(GarantirDocenteDeUtilizadorAction::class);
        $dInactivo = $acao->executar($inactivo);
        $dActivo = $acao->executar($activo);

        $this->assertSame(0, (int) $dInactivo->fresh()->estado);
        $this->assertSame('Inativo', $dInactivo->fresh()->estado_descricao);
        $this->assertSame(1, (int) $dActivo->fresh()->estado);
    }

    public function test_comando_sincronizar_respeita_o_estado_da_conta(): void
    {
        $inactivo = $this->criarProfessor('inactivo@example.com');
        $inactivo->update(['estado' => 0]);

        $this->artisan('docentes:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(0, (int) Docente::query()->firstOrFail()->estado);
    }

    public function test_garantir_usa_o_bi_provisorio_sem_bi_u_id(): void
    {
        $user = $this->criarProfessor('prof-bi@example.com');

        $docente = app(GarantirDocenteDeUtilizadorAction::class)->executar($user);

        $this->assertSame('SEM-BI-U' . $user->id, $docente->dadosPessoa->numero_identificacao);
    }

    public function test_garantir_com_bi_provisorio_ja_existente_da_validation_exception_e_nao_404(): void
    {
        $user = $this->criarProfessor('prof-dup@example.com');
        DadosPessoa::create(['nome_completo' => 'Outra', 'numero_identificacao' => 'SEM-BI-U' . $user->id, 'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR]);

        try {
            app(GarantirDocenteDeUtilizadorAction::class)->executar($user);
            $this->fail('Devia ter lançado ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('docente', $e->errors());
        }

        $this->assertSame(0, Docente::query()->count());
    }

    public function test_eliminar_docente_com_conta_e_bloqueado(): void
    {
        $docente = $this->criarDocente('Com conta', comAcesso: true);

        try {
            app(EliminarDocenteAction::class)->executar($docente);
            $this->fail('Devia ter sido bloqueado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('docente', $e->errors());
            $this->assertStringContainsString('tem acesso ao sistema', $e->errors()['docente'][0]);
        }

        $this->assertNotNull(Docente::find($docente->id));
    }
}
