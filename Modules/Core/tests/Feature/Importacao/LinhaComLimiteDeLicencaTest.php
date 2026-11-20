<?php

namespace Modules\Core\Tests\Feature\Importacao;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Modules\Core\Importacao\ImportadorDeEntidade;
use Modules\Core\Importacao\Jobs\ImportarImportacaoJob;
use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Core\Licenciamento\Enums\Recurso;
use Modules\Core\Licenciamento\Exceptions\LimiteDeLicencaExcedido;
use Modules\Core\Models\Importacao;
use Modules\Core\Models\ImportacaoLinha;
use Modules\Usuario\Models\User;
use RuntimeException;
use Throwable;
use Tests\TestCase;

class LinhaComLimiteDeLicencaTest extends TestCase
{
    use RefreshDatabase;

    private function importacaoCom(Throwable $excepcao): Importacao
    {
        $importador = Mockery::mock(ImportadorDeEntidade::class);
        $importador->shouldReceive('chave')->andReturn('entidade-teste');
        $importador->shouldReceive('criar')->andThrow($excepcao);
        $this->app->instance(ImportadorDeEntidade::class, $importador);
        $this->app->make(RegistoDeImportadores::class)->registar(ImportadorDeEntidade::class);

        $user = User::create(['name' => 'Admin', 'email' => 'imp@example.com', 'password' => Hash::make('x')]);
        $importacao = Importacao::create(['user_id' => $user->id, 'entidade' => 'entidade-teste', 'nome_ficheiro' => 'x.csv', 'caminho' => 'x', 'estado' => Importacao::A_IMPORTAR]);
        ImportacaoLinha::create(['importacao_id' => $importacao->id, 'linha' => 2, 'dados' => ['a' => 1], 'estado' => ImportacaoLinha::VALIDA]);

        return $importacao;
    }

    public function test_linha_acima_do_limite_guarda_a_mensagem_clara_do_limite(): void
    {
        $importacao = $this->importacaoCom(LimiteDeLicencaExcedido::para(Recurso::ALUNOS, 5));

        app()->call([new ImportarImportacaoJob($importacao->id), 'handle']);

        $linha = ImportacaoLinha::where('importacao_id', $importacao->id)->sole();
        $this->assertSame(ImportacaoLinha::ERRO, $linha->estado);
        $this->assertCount(1, $linha->erros);
        $this->assertStringContainsString('limite de 5 alunos', $linha->erros[0]);
        $this->assertSame(1, $importacao->fresh()->com_erro);
    }

    public function test_outras_falhas_mantem_a_mensagem_generica(): void
    {
        $importacao = $this->importacaoCom(new RuntimeException('SQL secreto'));

        app()->call([new ImportarImportacaoJob($importacao->id), 'handle']);

        $linha = ImportacaoLinha::where('importacao_id', $importacao->id)->sole();
        $this->assertSame(['Não foi possível criar este registo. Confirme os dados desta linha.'], $linha->erros);
    }
}
