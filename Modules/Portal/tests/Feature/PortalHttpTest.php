<?php

namespace Modules\Portal\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Permissao\Enums\Perfil;
use Modules\Portal\Tests\Support\CenarioDeFamilia;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PortalHttpTest extends TestCase
{
    use CenarioDeFamilia;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCenario();
    }

    public function test_encarregado_com_um_filho_e_redireccionado_para_o_filho(): void
    {
        $enc = $this->criarEncarregado();
        $filho = $this->criarEducando('Filho', '2026-0001');
        $this->ligar($enc, $filho);

        $this->actingAs($enc)->get('/portal')->assertRedirect("/portal/{$filho->id}");
    }

    public function test_encarregado_com_varios_filhos_ve_a_lista(): void
    {
        $enc = $this->criarEncarregado();
        $this->ligar($enc, $this->criarEducando('Ana', '2026-0001'));
        $this->ligar($enc, $this->criarEducando('Zeca', '2026-0002'));

        $this->actingAs($enc)->get('/portal')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portal/Inicio')
            ->has('educandos', 2)
            ->where('educando', null));
    }

    public function test_pagina_do_educando_traz_os_dados_e_os_acessos(): void
    {
        $enc = $this->criarEncarregado();
        $ana = $this->criarEducando('Ana', '2026-0001');
        $this->ligar($enc, $ana, 'Mãe');
        $this->ligar($enc, $this->criarEducando('Zeca', '2026-0002'));

        $this->actingAs($enc)->get("/portal/{$ana->id}")->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portal/Inicio')
            ->where('educando.id', $ana->id)
            ->where('educando.nome', 'Ana')
            ->where('educando.numero_matricula', '2026-0001')
            ->where('educando.parentesco', 'Mãe')
            ->has('educandos', 2)
            ->where('acessos', fn ($acessos) => collect($acessos)->contains('nota.ver-proprias')));
    }

    public function test_filho_de_outro_encarregado_da_404(): void
    {
        $enc = $this->criarEncarregado();
        $this->ligar($enc, $this->criarEducando('Meu', '2026-0001'));
        $alheio = $this->criarEducando('Alheio', '2026-0002');
        $this->ligar($this->criarEncarregado('outro@example.com'), $alheio);

        $this->actingAs($enc)->get("/portal/{$alheio->id}")->assertNotFound();
        $this->actingAs($enc)->get('/portal/999999')->assertNotFound();
    }

    public function test_educando_inactivo_da_404_e_nao_e_listado(): void
    {
        $enc = $this->criarEncarregado();
        $activo = $this->criarEducando('Activo', '2026-0001');
        $inactivo = $this->criarEducando('Inactivo', '2026-0002', estado: 0);
        $this->ligar($enc, $activo);
        $this->ligar($enc, $inactivo);

        $this->actingAs($enc)->get("/portal/{$inactivo->id}")->assertNotFound();
        $this->actingAs($enc)->get("/portal/{$activo->id}")->assertOk()->assertInertia(fn (Assert $p) => $p->has('educandos', 1));
    }

    public function test_encarregado_sem_educandos_ve_estado_vazio(): void
    {
        $this->actingAs($this->criarEncarregado())->get('/portal')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Portal/Inicio')
            ->has('educandos', 0)
            ->where('educando', null));
    }

    public function test_quem_nao_e_encarregado_recebe_403(): void
    {
        foreach ([Perfil::ADMIN_ESCOLA, Perfil::PROFESSOR, Perfil::ALUNO] as $i => $perfil) {
            $user = $this->criarComPerfil(User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com", 'password' => 'x']), $perfil);

            $this->actingAs($user)->get('/portal')->assertForbidden();
        }
    }

    public function test_visitante_vai_para_o_login(): void
    {
        $this->get('/portal')->assertRedirect('/login');
    }
}
