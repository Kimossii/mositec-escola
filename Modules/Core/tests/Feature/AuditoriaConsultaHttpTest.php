<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Core\Actions\RegistarAuditoriaTenantAction;
use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\AuditoriaTenant;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class AuditoriaConsultaHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function registar(string $accao, ?int $utilizadorId = null, string $entidade = 'roles'): void
    {
        app(RegistarAuditoriaTenantAction::class)->executar(
            new AuditoriaTenantDTO($accao, $entidade, 1, ['a' => 1], ['a' => 2], $utilizadorId),
        );
    }

    public function test_admin_ve_lista_paginada_mais_recente_primeiro(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $this->registar('perfil.criado', $admin->id);
        $this->registar('perfil.eliminado', $admin->id);
        $this->actingAs($admin);

        $this->get('/auditoria')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Core/Auditoria/Index')
            ->has('registos.data', 2)
            ->where('registos.data.0.accao', 'perfil.eliminado')
            ->where('registos.data.0.utilizador', $admin->name)
            ->where('registos.data.0.detalhe.depois.a', 2));
    }

    public function test_funcionario_e_professor_recebem_403(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'f@example.com'));
        $this->get('/auditoria')->assertForbidden();

        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'p@example.com'));
        $this->get('/auditoria')->assertForbidden();
    }

    public function test_filtros_aplicam_se(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $this->registar('perfil.criado', $admin->id);
        $this->registar('matricula.criada', $admin->id, 'matriculas');
        $this->actingAs($admin);

        $this->get('/auditoria?accao=perfil.')->assertInertia(fn (Assert $p) => $p
            ->has('registos.data', 1)->where('registos.data.0.accao', 'perfil.criado'));
        $this->get('/auditoria?entidade_tipo=matriculas')->assertInertia(fn (Assert $p) => $p->has('registos.data', 1));
        $this->get('/auditoria?utilizador=inexistente')->assertInertia(fn (Assert $p) => $p->has('registos.data', 0));
        $this->get('/auditoria?de=2999-01-01')->assertInertia(fn (Assert $p) => $p->has('registos.data', 0));
        $this->get('/auditoria?ate=2000-01-01')->assertInertia(fn (Assert $p) => $p->has('registos.data', 0));
    }

    public function test_utilizador_apagado_mostra_nulo(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $this->registar('perfil.criado', null);
        AuditoriaTenant::query()->update(['utilizador_id' => null]);
        $this->actingAs($admin);

        $this->get('/auditoria')->assertInertia(fn (Assert $p) => $p
            ->where('registos.data.0.utilizador', null));
    }

    public function test_nao_mostra_registos_de_outra_escola(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->registar('perfil.so_em_b'));
        $this->registar('perfil.so_em_a', $admin->id);
        $this->actingAs($admin);

        $this->get('/auditoria')->assertInertia(fn (Assert $p) => $p
            ->has('registos.data', 1)->where('registos.data.0.accao', 'perfil.so_em_a'));
    }

    public function test_nao_existem_rotas_para_editar_ou_apagar_o_rasto(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $rota) {
            if ($rota->uri() === 'auditoria') {
                $this->assertSame(['GET', 'HEAD'], $rota->methods());
            }
        }
    }
}
