<?php

namespace Modules\Core\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Models\EnvioEmail;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

class EnviosEmailConsultaHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function registar(EstadoEnvioEmail $estado, CategoriaEmail $categoria = CategoriaEmail::ESSENCIAL, ?string $erro = null): EnvioEmail
    {
        return EnvioEmail::create([
            'notificacao' => 'RecuperarSenhaNotificacao',
            'categoria' => $categoria,
            'estado' => $estado,
            'destinatario_mascarado' => 'p***@gmail.com',
            'erro' => $erro,
        ]);
    }

    public function test_admin_ve_a_lista_mais_recente_primeiro_e_o_resumo(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $this->registar(EstadoEnvioEmail::ENVIADO);
        $this->registar(EstadoEnvioEmail::FALHOU, erro: 'Connection refused');
        $this->registar(EstadoEnvioEmail::DESCARTADO_LIMITE);
        $this->actingAs($admin);

        $this->get('/envios-email')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Core/EnviosEmail/Index')
            ->has('registos.data', 3)
            ->where('registos.data.0.estado', EstadoEnvioEmail::DESCARTADO_LIMITE->value)
            ->where('registos.data.1.erro', 'Connection refused')
            ->where('registos.data.1.destinatario', 'p***@gmail.com')
            ->where('resumo.enviados', 1)
            ->where('resumo.falhas', 1)
            ->where('resumo.descartados', 1));
    }

    public function test_a_resposta_nunca_traz_o_endereco_completo(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $this->registar(EstadoEnvioEmail::ENVIADO);
        $this->actingAs($admin);

        $this->get('/envios-email')->assertInertia(fn (Assert $p) => $p
            ->missing('registos.data.0.destinatario_user_id')
            ->missing('registos.data.0.tenant_id'));
    }

    public function test_funcionario_e_professor_recebem_403(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'f@example.com'));
        $this->get('/envios-email')->assertForbidden();

        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'p@example.com'));
        $this->get('/envios-email')->assertForbidden();
    }

    public function test_filtros_aplicam_se(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $this->registar(EstadoEnvioEmail::ENVIADO);
        $this->registar(EstadoEnvioEmail::FALHOU, CategoriaEmail::OPCIONAL);
        $this->actingAs($admin);

        $this->get('/envios-email?estado=1')->assertInertia(fn (Assert $p) => $p
            ->has('registos.data', 1)->where('registos.data.0.estado', 1));
        $this->get('/envios-email?categoria=opcional')->assertInertia(fn (Assert $p) => $p->has('registos.data', 1));
        $this->get('/envios-email?estado=0')->assertInertia(fn (Assert $p) => $p->has('registos.data', 1));
        $this->get('/envios-email?de=2999-01-01')->assertInertia(fn (Assert $p) => $p->has('registos.data', 0));
        $this->get('/envios-email?estado=9')->assertSessionHasErrors('estado');
    }

    public function test_nao_mostra_envios_de_outra_escola(): void
    {
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->registar(EstadoEnvioEmail::FALHOU));
        $this->registar(EstadoEnvioEmail::ENVIADO);
        $this->actingAs($admin);

        $this->get('/envios-email')->assertInertia(fn (Assert $p) => $p
            ->has('registos.data', 1)->where('resumo.falhas', 0));
    }

    public function test_a_rota_so_aceita_leitura(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $rota) {
            if ($rota->uri() === 'envios-email') {
                $this->assertSame(['GET', 'HEAD'], $rota->methods());
            }
        }
    }
}
