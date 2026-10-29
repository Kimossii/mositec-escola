<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * Item «Atribuições docentes» nos dois menus partilhados (sidebar e cabeçalho), com a mesma permissão, e quem o vê:
 * os menus filtram os links pela prop partilhada `permissoes` (podeVer), por isso o admin da escola recebe
 * `atribuicao-docente.ver` e o professor não. Lê os ficheiros do menu sem os executar e ignora o que está comentado.
 */
class MenuAtribuicoesDocentesTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private const ITEM_SIDEBAR = "{ href: '/docentes/atribuicoes', title: 'Atribuições docentes', permissao: 'atribuicao-docente.ver' }";

    private const ITEM_HEADER = "{ href: '/docentes/atribuicoes', label: 'Atribuições docentes', permissao: 'atribuicao-docente.ver' }";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function ficheiro(string $relativo): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $relativo);
    }

    private function semComentariosHtml(string $codigo): string
    {
        return preg_replace('/<!--.*?-->/s', '', $codigo);
    }

    private function semComentariosJs(string $codigo): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $codigo);
    }

    private function utilizador(Perfil $perfil): User
    {
        $u = User::create(['name' => 'U ' . $perfil->value, 'email' => $perfil->value . '@example.com', 'password' => Hash::make('segredo123')]);
        $u->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->firstOrFail()->id]);

        return $u;
    }

    public function test_a_sidebar_activa_a_seccao_pessoal_so_com_atribuicoes_docentes(): void
    {
        [$template, $script] = explode('<script setup>', $this->ficheiro('resources/js/Components/Layout/SidebarMenuWrapper.vue'), 2);
        $template = $this->semComentariosHtml($template);
        $script = $this->semComentariosJs($script);

        $this->assertStringContainsString('<SidebarAccordion :item="pessoal" heading="Pessoal" />', $template);
        $this->assertStringNotContainsString(':item="frequencia"', $template, 'As outras secções por implementar continuam comentadas.');
        $this->assertSame(1, preg_match("#const pessoal = \{(.*?)\n\}#s", $script, $bloco), 'const pessoal tem de estar activo.');
        $this->assertStringContainsString(self::ITEM_SIDEBAR, $bloco[1]);
        $this->assertSame(1, preg_match_all('/href:/', $bloco[1]), 'Só «Atribuições docentes» activo; os outros três itens ficam comentados.');
    }

    public function test_o_menu_academico_do_cabecalho_tem_o_mesmo_link_com_a_mesma_permissao(): void
    {
        $composable = $this->semComentariosJs($this->ficheiro('resources/js/Composables/useAcademicoMenu.js'));

        $this->assertStringContainsString(self::ITEM_HEADER, $composable);
    }

    public function test_o_admin_recebe_a_permissao_que_mostra_o_item(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        $this->get(route('dashboard.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permissoes', fn ($p) => collect($p)->contains('atribuicao-docente.ver')));
    }

    public function test_o_professor_nao_recebe_a_permissao_e_nao_ve_o_item(): void
    {
        $this->actingAs($this->utilizador(Perfil::PROFESSOR));

        $this->get(route('dashboard.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permissoes', fn ($p) => ! collect($p)->contains('atribuicao-docente.ver')));
    }

    public function test_a_pagina_inertia_do_index_existe_onde_o_resolver_a_procura(): void
    {
        $this->cenarioDeDocencia();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        $this->get(route('atribuicoes-docentes.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docente/Atribuicoes/Index')
                ->has('anoLectivoId')->has('vista')->has('porTurma')->has('atribuicoes.data')->has('filtros')
                ->has('opcoes.anosLectivos')->has('opcoes.turmas')->has('opcoes.professores'));

        // resources/js/app.js resolve «Modulo/Resto» para /Modules/Modulo/resources/js/Pages/Resto.vue
        $this->assertFileExists(dirname(__DIR__, 2) . '/Modules/Docente/resources/js/Pages/Atribuicoes/Index.vue');
    }
}
