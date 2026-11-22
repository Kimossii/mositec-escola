<?php

namespace Modules\Usuario\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Actions\RegistarAcessoDocumentoPessoaAction;
use Modules\Usuario\Enums\AcaoAcessoDocumento;
use Modules\Usuario\Models\AcessoDocumentoPessoa;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\GestaoAcessoDocumentoPessoaService;
use RuntimeException;
use Tests\TestCase;

class AcessoDocumentoPessoaTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email = 'a@example.com'): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
    }

    private function pessoa(string $bi = 'BI0001'): DadosPessoa
    {
        return DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => $bi, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
    }

    private function pedido(): Request
    {
        return Request::create('/x', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_USER_AGENT' => 'PHPUnit-UA']);
    }

    public function test_regista_acesso_com_utilizador_pessoa_acao_ip(): void
    {
        $u = $this->utilizador();
        $p = $this->pessoa();

        app(RegistarAcessoDocumentoPessoaAction::class)->executar($u, $p, AcaoAcessoDocumento::Download, null, $this->pedido());

        $a = AcessoDocumentoPessoa::firstOrFail();
        $this->assertSame($u->id, $a->user_id);
        $this->assertSame($p->id, $a->dados_pessoa_id);
        $this->assertSame('download', $a->acao->value);
        $this->assertSame('Download', $a->acao_descricao);
        $this->assertSame('10.1.2.3', $a->ip);
        $this->assertSame('PHPUnit-UA', $a->user_agent);
    }

    public function test_documento_id_fica_nulo_em_listagem(): void
    {
        app(RegistarAcessoDocumentoPessoaAction::class)->executar($this->utilizador(), $this->pessoa(), AcaoAcessoDocumento::Listar, null, $this->pedido());

        $this->assertNull(AcessoDocumentoPessoa::firstOrFail()->documento_pessoa_id);
    }

    public function test_falha_ao_gravar_nao_lanca_excepcao(): void
    {
        AcessoDocumentoPessoa::creating(fn () => throw new RuntimeException('falha'));

        app(RegistarAcessoDocumentoPessoaAction::class)->executar($this->utilizador(), $this->pessoa(), AcaoAcessoDocumento::Listar, null, $this->pedido());

        $this->assertSame(0, AcessoDocumentoPessoa::count());
    }

    public function test_registo_e_so_de_insercao(): void
    {
        app(RegistarAcessoDocumentoPessoaAction::class)->executar($this->utilizador(), $this->pessoa(), AcaoAcessoDocumento::Listar, null, $this->pedido());
        $a = AcessoDocumentoPessoa::firstOrFail();

        $this->expectException(\LogicException::class);
        $a->delete();
    }

    public function test_listar_filtra_por_pessoa_utilizador_data_e_acao(): void
    {
        $u1 = $this->utilizador('u1@example.com');
        $u2 = $this->utilizador('u2@example.com');
        $p1 = $this->pessoa('BI1');
        $p2 = $this->pessoa('BI2');
        $acao = app(RegistarAcessoDocumentoPessoaAction::class);
        $acao->executar($u1, $p1, AcaoAcessoDocumento::Download, null, $this->pedido());
        $acao->executar($u2, $p2, AcaoAcessoDocumento::Visualizar, null, $this->pedido());
        AcessoDocumentoPessoa::where('user_id', $u2->id)->update(['created_at' => Carbon::parse('2020-01-01')]);

        $s = app(GestaoAcessoDocumentoPessoaService::class);

        $this->assertSame(2, $s->listar([])->total());
        $this->assertSame(1, $s->listar(['dados_pessoa_id' => $p1->id])->total());
        $this->assertSame(1, $s->listar(['user_id' => $u2->id])->total());
        $this->assertSame(1, $s->listar(['acao' => 'download'])->total());
        $this->assertSame(1, $s->listar(['de' => '2024-01-01'])->total());
        $this->assertSame(1, $s->listar(['ate' => '2020-12-31'])->total());
    }

    public function test_eliminar_utilizador_e_pessoa_com_acessos_nao_falha_e_o_registo_fica_legivel(): void
    {
        $u = $this->utilizador('quem@example.com');
        $p = $this->pessoa('BI-DEL');
        app(RegistarAcessoDocumentoPessoaAction::class)->executar($u, $p, AcaoAcessoDocumento::Listar, null, $this->pedido());

        $u->delete();
        $p->delete();

        $a = AcessoDocumentoPessoa::firstOrFail();
        $this->assertNull($a->user_id);
        $this->assertNull($a->dados_pessoa_id);
        $this->assertSame('U', $a->utilizador_nome);
        $this->assertSame('Ana Silva', $a->pessoa_nome);
        $linha = app(GestaoAcessoDocumentoPessoaService::class)->listar([])->items()[0];
        $this->assertSame('U', $linha['utilizador']);
        $this->assertSame('Ana Silva', $linha['pessoa']);
    }
}
