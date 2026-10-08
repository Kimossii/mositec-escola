<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Contracts\ProcuraAlunoParaConta;
use Modules\Core\DTO\AlunoParaConta;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class ProcuraAlunoParaContaTest extends TestCase
{
    use RefreshDatabase;

    private function criarAluno(string $matricula, string $nome): Aluno
    {
        $pessoa = DadosPessoa::create(['nome_completo' => $nome, 'numero_identificacao' => 'BI' . $matricula, 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        return Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $matricula,
        ]);
    }

    public function test_devolve_o_dto_do_aluno_do_tenant_corrente(): void
    {
        $aluno = $this->criarAluno('2026-0001', 'Ana Silva');

        $dto = app(ProcuraAlunoParaConta::class)->procurar('2026-0001');

        $this->assertInstanceOf(AlunoParaConta::class, $dto);
        $this->assertSame('Ana Silva', $dto->nome);
        $this->assertSame('2026-0001', $dto->numeroMatricula);
        $this->assertSame($aluno->dados_pessoa_id, $dto->dadosPessoaId);
        $this->assertSame(1, $dto->estado);
        $this->assertSame('Ativo', $dto->estadoDescricao);
    }

    public function test_aluno_de_outro_tenant_ou_matricula_parcial_nao_e_encontrado(): void
    {
        $escolaB = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($escolaB, fn () => $this->criarAluno('2026-0099', 'Aluno de B'));
        $this->criarAluno('2026-0001', 'Ana Silva');

        $this->assertNull(app(ProcuraAlunoParaConta::class)->procurar('2026-0099'));
        $this->assertNull(app(ProcuraAlunoParaConta::class)->procurar('2026-000'));
        $this->assertNull(app(ProcuraAlunoParaConta::class)->procurar('2026-00%'));
    }
}
