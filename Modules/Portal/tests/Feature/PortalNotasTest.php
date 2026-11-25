<?php

namespace Modules\Portal\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Actions\GuardarRegraAvaliacaoAction;
use Modules\Avaliacao\DTO\RegraAvaliacaoDTO;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** O encarregado vê as notas do filho (as mesmas que o filho vê), e só as do filho. */
class PortalNotasTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private array $c;

    private User $ana;

    private User $bia;

    private User $pai;

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
        $this->pai = $this->criarUtilizadorComPerfil(Perfil::ENCARREGADO, 'pai@example.com');
        $this->pai->educandos()->attach($this->ana->id, ['parentesco' => 'Pai']);

        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com'))
            ->post("/avaliacao/pautas/{$this->c['turma']->id}/aprovar")->assertRedirect();
    }

    private function contaDoAluno($inscricao, string $email): User
    {
        $user = $this->criarUtilizadorComPerfil(Perfil::ALUNO, $email);
        $user->update(['dados_pessoa_id' => $inscricao->matricula->aluno->dados_pessoa_id]);

        return $user;
    }

    public function test_o_pai_ve_as_notas_do_filho(): void
    {
        $this->actingAs($this->pai)->get("/portal/{$this->ana->id}/notas")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Avaliacao/MinhasNotas/Index')
            ->where('educando.id', $this->ana->id)
            ->where('boletim_base', "/portal/{$this->ana->id}/notas")
            ->has('anos', 1)
            ->where('anos.0.matricula_id', $this->matriculaAna)
            ->where('anos.0.resultado', 'Transita')
            ->where('anos.0.linhas.0.valores.3', '7.06'));
    }

    public function test_o_boletim_do_filho_descarrega_e_o_de_outro_aluno_da_404(): void
    {
        $this->actingAs($this->pai)->get("/portal/{$this->ana->id}/notas/{$this->matriculaAna}/boletim")->assertOk();
        $this->actingAs($this->pai)->get("/portal/{$this->ana->id}/notas/{$this->matriculaBia}/boletim")->assertNotFound();
    }

    public function test_notas_de_um_aluno_que_nao_e_filho_da_404(): void
    {
        $this->actingAs($this->pai)->get("/portal/{$this->bia->id}/notas")->assertNotFound();
        $this->actingAs($this->pai)->get("/portal/{$this->bia->id}/notas/{$this->matriculaBia}/boletim")->assertNotFound();
    }

    public function test_so_com_a_permissao_de_notas_no_perfil_aluno(): void
    {
        RolePermissao::where('role_id', Role::where('nome', Perfil::ALUNO->value)->value('id'))
            ->where('modulo_id', Modulo::where('nome', ModuloEnum::NOTA->value)->value('id'))
            ->delete();
        app(PermissaoCache::class)->invalidarTudo();

        $this->actingAs($this->pai)->get("/portal/{$this->ana->id}/notas")->assertForbidden();
    }

    public function test_quem_nao_e_encarregado_nao_usa_as_rotas_do_portal(): void
    {
        $this->actingAs($this->ana)->get("/portal/{$this->ana->id}/notas")->assertForbidden();
    }
}
