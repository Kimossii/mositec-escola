<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** O aluno consulta só as suas notas e só de pautas definitivas. */
class MinhasNotasTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private User $ana;

    private User $bia;

    private int $matriculaAna;

    private int $matriculaBia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->c = $this->cenarioDeAvaliacao();
        app(GuardarRegraAvaliacaoAction::class)->executar(new RegraAvaliacaoDTO($this->c['ano']->id, [$this->c['nivel']->id], 10, '5'));

        $inscAna = $this->inscreverAluno($this->c, 'Ana');
        $inscBia = $this->inscreverAluno($this->c, 'Bia');
        $this->notasCompletas($this->c, $inscAna, [1 => ['5.33', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);
        $this->notasCompletas($this->c, $inscBia, [1 => ['9', '9'], 2 => ['9', '9'], 3 => ['9', '9']]);

        $this->matriculaAna = $inscAna->matricula_id;
        $this->matriculaBia = $inscBia->matricula_id;
        $this->ana = $this->contaDoAluno($inscAna, 'ana@example.com');
        $this->bia = $this->contaDoAluno($inscBia, 'bia@example.com');
    }

    private function contaDoAluno($inscricao, string $email): User
    {
        $user = $this->criarUtilizadorComPerfil(Perfil::ALUNO, $email);
        $user->update(['dados_pessoa_id' => $inscricao->matricula->aluno->dados_pessoa_id]);

        return $user;
    }

    private function aprovar(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'))
            ->post("/avaliacao/pautas/{$this->c['turma']->id}/aprovar")->assertRedirect();
    }

    public function test_com_a_pauta_provisoria_o_aluno_nao_ve_notas_nem_boletim(): void
    {
        $this->actingAs($this->ana)->get('/minhas-notas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/MinhasNotas/Index')->has('anos', 0));
        $this->actingAs($this->ana)->get("/minhas-notas/{$this->matriculaAna}/boletim")->assertNotFound();
    }

    public function test_com_a_pauta_definitiva_o_aluno_ve_so_as_suas_notas(): void
    {
        $this->aprovar();

        $this->actingAs($this->ana)->get('/minhas-notas')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->has('anos', 1)
            ->where('anos.0.matricula_id', $this->matriculaAna)
            ->where('anos.0.resultado', 'Transita')
            ->where('anos.0.linhas.0.valores.3', '7.06')
            ->has('anos.0.linhas', 1));
    }

    public function test_o_boletim_proprio_descarrega_e_o_de_outro_aluno_da_404(): void
    {
        $this->aprovar();

        $this->actingAs($this->ana)->get("/minhas-notas/{$this->matriculaAna}/boletim")->assertOk();
        $this->actingAs($this->ana)->get("/minhas-notas/{$this->matriculaBia}/boletim")->assertNotFound();
    }

    public function test_quem_nao_e_aluno_nao_acede(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin2@example.com'))->get('/minhas-notas')->assertForbidden();
    }

    public function test_o_aluno_nao_acede_a_pauta_da_turma(): void
    {
        $this->actingAs($this->ana)->get('/avaliacao/pautas?turma='.$this->c['turma']->id)->assertForbidden();
    }
}
