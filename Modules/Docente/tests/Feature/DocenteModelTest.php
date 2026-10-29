<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Docente\Models\Docente;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocenteModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarDadosPessoa(string $bi = 'BI-DOC-1'): DadosPessoa
    {
        return DadosPessoa::create([
            'nome_completo' => 'Maria Docente',
            'numero_identificacao' => $bi,
            'tipo_pessoa' => DadosPessoa::TIPO_PROFESSOR,
        ]);
    }

    private function criarDocente(?DadosPessoa $pessoa = null): Docente
    {
        return Docente::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => ($pessoa ?? $this->criarDadosPessoa())->id,
        ]);
    }

    public function test_docente_pertence_ao_tenant(): void
    {
        $docenteA = $this->criarDocente();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $docenteB = $this->noTenant($outro, fn () => $this->criarDocente($this->criarDadosPessoa('BI-DOC-2')));

        $this->assertSame([$docenteA->id], Docente::pluck('id')->all());
        $this->assertNull(Docente::find($docenteB->id));
        $this->assertSame($this->tenant->id, (int) $docenteA->tenant_id);
        $this->assertSame($outro->id, (int) $docenteB->tenant_id);
    }

    public function test_dados_pessoa_unica_por_docente(): void
    {
        $pessoa = $this->criarDadosPessoa();
        $this->criarDocente($pessoa);

        $this->expectException(QueryException::class);

        $this->criarDocente($pessoa);
    }

    public function test_estado_descricao_sincroniza(): void
    {
        $docente = $this->criarDocente();

        $this->assertSame(1, $docente->fresh()->estado);
        $this->assertSame('Ativo', $docente->fresh()->estado_descricao);

        $docente->update(['estado' => 0]);

        $this->assertSame('Inativo', $docente->fresh()->estado_descricao);
    }

    public function test_relacoes_basicas(): void
    {
        $docente = $this->criarDocente();

        $this->assertSame('Maria Docente', $docente->dadosPessoa->nome_completo);
        $this->assertSame(Estabelecimento::current()->id, $docente->estabelecimento->id);
        $this->assertInstanceOf(HasMany::class, $docente->atribuicoes());
        $this->assertCount(0, $docente->atribuicoes);
    }

    public function test_tem_acesso_quando_existe_user_com_a_mesma_pessoa(): void
    {
        $pessoa = $this->criarDadosPessoa();
        $docente = $this->criarDocente($pessoa);

        $this->assertFalse($docente->temAcesso());
        $this->assertNull($docente->utilizador());

        $user = User::create([
            'name' => 'Maria', 'email' => 'maria@example.com', 'password' => Hash::make('x'),
            'dados_pessoa_id' => $pessoa->id,
        ]);

        $this->assertTrue($docente->temAcesso());
        $this->assertSame($user->id, $docente->utilizador()->id);
    }

    public function test_sem_acesso_se_o_user_for_de_outro_tenant(): void
    {
        $pessoa = $this->criarDadosPessoa();
        $docente = $this->criarDocente($pessoa);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, fn () => User::create([
            'name' => 'Intruso', 'email' => 'intruso@example.com', 'password' => Hash::make('x'),
            'dados_pessoa_id' => $pessoa->id,
        ]));

        $this->assertFalse($docente->temAcesso());
        $this->assertNull($docente->utilizador());
    }
}
