<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Actions\RegistarAcessoDocumentoPessoaAction;
use Modules\Usuario\Enums\AcaoAcessoDocumento;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class AcessosDocumentosHttpTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function registarAcesso(string $nomePessoa = 'Ana Silva'): void
    {
        $u = $this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'func'.random_int(1, 99999).'@example.com');
        $p = DadosPessoa::create(['nome_completo' => $nomePessoa, 'numero_identificacao' => 'BI'.random_int(1, 999999), 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        app(RegistarAcessoDocumentoPessoaAction::class)->executar($u, $p, AcaoAcessoDocumento::Download, null, Request::create('/x'));
    }

    public function test_admin_ve_lista_paginada(): void
    {
        $this->registarAcesso();
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'));

        $this->get('/acessos-documentos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Usuario/AcessosDocumentos/Index')
            ->has('acessos.data', 1)
            ->where('acessos.data.0.pessoa', 'Ana Silva')
            ->where('acessos.data.0.acao', 'Download'));
    }

    public function test_funcionario_recebe_403(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::FUNCIONARIO, 'f@example.com'));
        $this->get('/acessos-documentos')->assertForbidden();
    }

    public function test_professor_recebe_403(): void
    {
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'p@example.com'));
        $this->get('/acessos-documentos')->assertForbidden();
    }

    public function test_filtros_aplicam_se(): void
    {
        $this->registarAcesso('Ana Silva');
        $this->registarAcesso('Bruno Costa');
        $this->actingAs($this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'adm@example.com'));

        $this->get('/acessos-documentos?pessoa=Bruno')->assertInertia(fn (Assert $page) => $page
            ->has('acessos.data', 1)->where('acessos.data.0.pessoa', 'Bruno Costa'));
        $this->get('/acessos-documentos?acao=negado')->assertInertia(fn (Assert $page) => $page->has('acessos.data', 0));
    }

    public function test_nao_existem_rotas_para_editar_ou_apagar_acessos(): void
    {
        foreach (Route::getRoutes() as $rota) {
            if (str_contains($rota->uri(), 'acessos-documentos')) {
                $this->assertSame(['GET', 'HEAD'], $rota->methods());
            }
        }
    }
}
