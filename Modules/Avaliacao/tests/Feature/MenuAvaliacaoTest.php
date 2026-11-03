<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Services\PermissionResolver;
use Tests\TestCase;

/**
 * «Avaliações / Notas» e «Configuração da avaliação» no menu Pedagógico (sidebar e cabeçalho), cada um com a sua
 * permissão. Os menus filtram os links pelas permissões partilhadas, por isso quem vê o quê é o que o resolver concede.
 */
class MenuAvaliacaoTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function ficheiro(string $relativo): string
    {
        return file_get_contents(dirname(__DIR__, 4).'/'.$relativo);
    }

    public function test_os_dois_menus_tem_os_itens_descomentados_com_a_permissao(): void
    {
        $sidebar = $this->ficheiro('resources/js/Components/Layout/SidebarMenuWrapper.vue');
        $cabecalho = $this->ficheiro('resources/js/Composables/usePedagogicoMenu.js');

        $this->assertStringContainsString("\n        { href: '/avaliacao/notas', title: 'Avaliações / Notas', permissao: 'nota.ver' },", $sidebar);
        $this->assertStringContainsString("\n        { href: '/avaliacao/configuracao', title: 'Configuração da avaliação', permissao: 'nota.configurar' },", $sidebar);
        $this->assertStringContainsString("\n        { href: '/avaliacao/pautas', title: 'Pautas', permissao: 'nota.exportar' },", $sidebar);
        $this->assertStringContainsString("\n            { href: '/avaliacao/pautas', label: 'Pautas', permissao: 'nota.exportar' },", $cabecalho);
        $this->assertStringContainsString("\n            { href: '/avaliacao/notas', label: 'Avaliações / Notas', permissao: 'nota.ver' },", $cabecalho);
        $this->assertStringContainsString("\n            { href: '/avaliacao/configuracao', label: 'Configuração da avaliação', permissao: 'nota.configurar' },", $cabecalho);
    }

    public function test_admin_ve_os_dois_itens_e_o_professor_so_as_notas(): void
    {
        $resolver = app(PermissionResolver::class);
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $professor = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'prof@example.com');

        $this->assertTrue($resolver->can($admin, 'nota.ver'));
        $this->assertTrue($resolver->can($admin, 'nota.configurar'));
        $this->assertTrue($resolver->can($professor, 'nota.ver'));
        $this->assertFalse($resolver->can($professor, 'nota.configurar'));
    }
}
