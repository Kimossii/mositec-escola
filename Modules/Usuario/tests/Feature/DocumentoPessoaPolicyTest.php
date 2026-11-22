<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Docente\Models\Docente;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocumentoPessoaPolicyTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $cenario;

    private User $professor;

    private DadosPessoa $alunoDoProfessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cenario = $this->cenarioDeAvaliacao();
        $this->professor = $this->professorAtribuido($this->cenario);
        $this->alunoDoProfessor = $this->inscreverAluno($this->cenario)->matricula->aluno->dadosPessoa;
    }

    private function pode(User $u, DadosPessoa $alvo): bool
    {
        return Gate::forUser($u)->allows('verDocumentosPessoa', $alvo);
    }

    /** Aluno matriculado noutra turma (sem professor atribuído). */
    private function alunoDeOutraTurma(): DadosPessoa
    {
        $outro = $this->cenarioDeAvaliacao('PRI_4', 'B');

        return $this->inscreverAluno($outro, 'Outro')->matricula->aluno->dadosPessoa;
    }

    public function test_pessoa_ve_os_proprios(): void
    {
        $this->assertTrue($this->pode($this->professor, DadosPessoa::findOrFail($this->professor->dados_pessoa_id)));
    }

    public function test_admin_ve_qualquer(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $this->assertTrue($this->pode($admin, $this->alunoDeOutraTurma()));
    }

    private function darVerDocumentosAoPerfil(Perfil $perfil): void
    {
        RolePermissao::create([
            'role_id' => Role::where('nome', $perfil->value)->firstOrFail()->id,
            'modulo_id' => Modulo::where('nome', ModuloEnum::DOCUMENTO_PESSOA->value)->firstOrFail()->id,
            'acao_id' => Acao::where('nome', 'ver')->firstOrFail()->id,
        ]);
    }

    public function test_funcionario_com_permissao_de_documentos_ve_qualquer(): void
    {
        $f = $this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'func@example.com');
        $this->darVerDocumentosAoPerfil(Perfil::FUNCIONARIO);
        $this->assertTrue($this->pode($f, $this->alunoDeOutraTurma()));
    }

    public function test_aluno_so_ve_os_proprios(): void
    {
        $u = $this->criarUtilizadorComPerfil(Perfil::ALUNO, 'aluno@example.com');
        $u->update(['dados_pessoa_id' => $this->alunoDoProfessor->id]);

        $this->assertTrue($this->pode($u, $this->alunoDoProfessor));
        $this->assertFalse($this->pode($u, $this->alunoDeOutraTurma()));
    }

    public function test_professor_ve_aluno_da_sua_turma_com_atribuicao_em_vigor(): void
    {
        $this->assertTrue($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_professor_nao_ve_aluno_de_outra_turma(): void
    {
        $this->assertFalse($this->pode($this->professor, $this->alunoDeOutraTurma()));
    }

    public function test_professor_nao_ve_colega_docente(): void
    {
        $colega = $this->criarDocenteDeAvaliacao()->dadosPessoa;
        $this->assertFalse($this->pode($this->professor, $colega));
    }

    public function test_professor_com_atribuicao_terminada_nao_ve(): void
    {
        AtribuicaoDocente::query()->update(['data_fim' => now()->subDay()->toDateString()]);
        $this->assertFalse($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_professor_com_atribuicao_agendada_nao_ve(): void
    {
        AtribuicaoDocente::query()->update(['data_inicio' => now()->addMonth()->toDateString()]);
        $this->assertFalse($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_professor_nao_ve_aluno_com_matricula_nao_activa(): void
    {
        Matricula::query()->update(['estado' => EstadoMatriculaEnum::TRANCADA->value]);
        $this->assertFalse($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_professor_com_perfil_sem_permissao_de_documentos_fica_so_com_a_turma(): void
    {
        $this->professor->roles()->attach(Role::where('nome', Perfil::FUNCIONARIO->value)->firstOrFail()->id);
        $this->assertFalse($this->pode($this->professor, $this->alunoDeOutraTurma()));
        $this->assertTrue($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_professor_com_atribuicao_aberta_mas_ano_lectivo_terminado_nao_ve(): void
    {
        $this->cenario['ano']->update(['data_fim' => now()->subDay()->toDateString()]);
        $this->assertFalse($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_professor_com_turma_eliminada_nao_ve(): void
    {
        $this->cenario['turma']->delete();
        $this->assertFalse($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_docente_inactivo_nao_ve(): void
    {
        Docente::query()->update(['estado' => 0]);
        $this->assertFalse($this->pode($this->professor, $this->alunoDoProfessor));
    }

    public function test_professor_com_perfil_funcionario_com_permissao_tem_ambito_largo(): void
    {
        $this->darVerDocumentosAoPerfil(Perfil::FUNCIONARIO);
        $this->professor->roles()->attach(Role::where('nome', Perfil::FUNCIONARIO->value)->firstOrFail()->id);
        $this->assertTrue($this->pode($this->professor, $this->alunoDeOutraTurma()));
    }

    public function test_utilizador_sem_docente_nem_gestao_nao_ve(): void
    {
        $semNada = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => 'segredo123']);
        $this->assertFalse($this->pode($semNada, $this->alunoDoProfessor));
    }

    public function test_encarregado_nao_ve(): void
    {
        $e = $this->criarUtilizadorComPerfil(Perfil::ENCARREGADO, 'enc@example.com');
        $this->assertFalse($this->pode($e, $this->alunoDoProfessor));
    }
}
