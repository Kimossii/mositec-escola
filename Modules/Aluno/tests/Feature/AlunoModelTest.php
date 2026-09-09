<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    private function criarDadosPessoa(string $numeroIdentificacao = 'BI0001'): DadosPessoal
    {
        return DadosPessoal::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => $numeroIdentificacao,
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);
    }

    public function test_regista_autoria_e_sincroniza_estado_descricao(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        Auth::login($staff);

        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = $this->criarDadosPessoa();

        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-0001',
        ]);

        $this->assertSame($staff->id, $aluno->criado_por);
        $this->assertSame($staff->id, $aluno->editado_por);
        $this->assertSame(Estado::ATIVO->value, $aluno->estado);
        $this->assertSame('Ativo', $aluno->estado_descricao);
    }

    public function test_dados_pessoa_id_e_unico_em_alunos(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = $this->criarDadosPessoa();

        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->expectException(QueryException::class);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0002']);
    }

    public function test_numero_matricula_e_unico_globalmente(): void
    {
        $estabelecimento = $this->criarEstabelecimento();

        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $this->criarDadosPessoa('BI0001')->id, 'numero_matricula' => '2026-0001']);

        $this->expectException(QueryException::class);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $this->criarDadosPessoa('BI0002')->id, 'numero_matricula' => '2026-0001']);
    }
}
