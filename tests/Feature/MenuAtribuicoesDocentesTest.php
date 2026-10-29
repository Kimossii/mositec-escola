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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Itens «Docentes» e «Atribuições docentes» nos dois menus partilhados (sidebar e cabeçalho), cada um com a sua
 * permissão, e quem os vê: os menus filtram os links pela prop partilhada `permissoes` (podeVer), por isso o admin da
 * escola recebe `docente.ver` e `atribuicao-docente.ver`, e o professor e o aluno nenhuma. Lê os ficheiros do menu sem
 * os executar e ignora o que está comentado.
 */
class MenuAtribuicoesDocentesTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;

    private const ITEM_SIDEBAR = "{ href: '/docentes/atribuicoes', title: 'Atribuições docentes', permissao: 'atribuicao-docente.ver' }";

    private const ITEM_HEADER = "{ href: '/docentes/atribuicoes', label: 'Atribuições docentes', permissao: 'atribuicao-docente.ver' }";

    private const ITEM_SIDEBAR_DOCENTES = "{ href: '/docentes', title: 'Docentes', exact: true, permissao: 'docente.ver' }";

    private const ITEM_HEADER_DOCENTES = "{ href: '/docentes', label: 'Docentes', exact: true, permissao: 'docente.ver' }";

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

    public function test_a_sidebar_activa_a_seccao_pessoal_com_docentes_antes_das_atribuicoes(): void
    {
        [$template, $script] = explode('<script setup>', $this->ficheiro('resources/js/Components/Layout/SidebarMenuWrapper.vue'), 2);
        $template = $this->semComentariosHtml($template);
        $script = $this->semComentariosJs($script);

        $this->assertStringContainsString('<SidebarAccordion :item="pessoal" heading="Pessoal" />', $template);
        $this->assertStringNotContainsString(':item="frequencia"', $template, 'As outras secções por implementar continuam comentadas.');
        $this->assertSame(1, preg_match("#const pessoal = \{(.*?)\n\}#s", $script, $bloco), 'const pessoal tem de estar activo.');
        $this->assertStringContainsString(self::ITEM_SIDEBAR_DOCENTES, $bloco[1]);
        $this->assertStringContainsString(self::ITEM_SIDEBAR, $bloco[1]);
        $this->assertLessThan(strpos($bloco[1], self::ITEM_SIDEBAR), strpos($bloco[1], self::ITEM_SIDEBAR_DOCENTES), '«Docentes» vem antes de «Atribuições docentes».');
        $this->assertSame(2, preg_match_all('/href:/', $bloco[1]), 'Só «Docentes» e «Atribuições docentes» activos; os outros itens ficam comentados.');
    }

    public function test_o_menu_academico_do_cabecalho_tem_os_mesmos_links_com_as_mesmas_permissoes(): void
    {
        $composable = $this->semComentariosJs($this->ficheiro('resources/js/Composables/useAcademicoMenu.js'));

        $this->assertStringContainsString(self::ITEM_HEADER_DOCENTES, $composable);
        $this->assertStringContainsString(self::ITEM_HEADER, $composable);
        $this->assertLessThan(strpos($composable, self::ITEM_HEADER), strpos($composable, self::ITEM_HEADER_DOCENTES));
    }

    public function test_o_admin_recebe_as_permissoes_que_mostram_os_dois_itens(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        $this->get(route('dashboard.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permissoes', fn ($p) => collect($p)->contains('atribuicao-docente.ver')
                && collect($p)->contains('docente.ver')));
    }

    public static function perfisSemPessoal(): array
    {
        return ['professor' => [Perfil::PROFESSOR], 'aluno' => [Perfil::ALUNO]];
    }

    #[DataProvider('perfisSemPessoal')]
    public function test_professor_e_aluno_nao_recebem_as_permissoes_e_nao_veem_nenhum_item(Perfil $perfil): void
    {
        $this->actingAs($this->utilizador($perfil));

        $this->get(route('dashboard.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permissoes', fn ($p) => ! collect($p)->contains('atribuicao-docente.ver')
                && ! collect($p)->contains('docente.ver')));
    }

    public function test_a_pagina_inertia_do_index_existe_onde_o_resolver_a_procura(): void
    {
        $this->cenarioDeDocencia();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        $this->get(route('atribuicoes-docentes.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docente/Atribuicoes/Index')
                ->has('anoLectivoId')->has('vista')->has('porTurma')->has('atribuicoes.data')->has('filtros')
                ->has('opcoes.anosLectivos')->has('opcoes.turmas')->has('opcoes.docentes'));

        // resources/js/app.js resolve «Modulo/Resto» para /Modules/Modulo/resources/js/Pages/Resto.vue
        $this->assertFileExists(dirname(__DIR__, 2) . '/Modules/Docente/resources/js/Pages/Atribuicoes/Index.vue');
    }

    public function test_a_pagina_inertia_dos_docentes_existe_e_recebe_as_props(): void
    {
        $this->cenarioDeDocencia();
        $this->criarDocente('Ana Sem Conta');
        $this->criarDocente('Bruno Com Conta', comAcesso: true);
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA));

        $this->get(route('docentes.index', ['acesso' => 'sem']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Docente/Docentes/Index')
                ->has('docentes.data', 1)
                ->has('docentes.data.0', fn (Assert $linha) => $linha
                    ->where('nome', 'Ana Sem Conta')->where('tem_acesso', false)->where('estado', 1)
                    ->hasAll(['id', 'bi', 'telefone', 'email', 'sexo', 'data_nascimento', 'telefone_alternativo']))
                ->has('docentes.links')
                ->where('filtros.acesso', 'sem')
                ->missing('indicadores')
                ->loadDeferredProps(fn (Assert $r) => $r->hasAll(['indicadores.total', 'indicadores.activos', 'indicadores.com_acesso', 'indicadores.sem_acesso'])));

        $this->assertFileExists(dirname(__DIR__, 2) . '/Modules/Docente/resources/js/Pages/Docentes/Index.vue');
    }
}
