<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class CriarAlunoActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    public function test_cria_aluno_com_nova_dados_pessoa_e_gera_numero_matricula(): void
    {
        $this->criarEstabelecimento();

        $dto = new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva',
            email: 'ana@example.com',
            telefone: '923000000',
            dataNascimento: '2010-05-01',
            sexo: DadosPessoal::SEXO_FEMININO,
            numeroIdentificacao: 'BI0001',
        );

        $aluno = app(CriarAlunoAction::class)->executar($dto);

        $this->assertInstanceOf(Aluno::class, $aluno);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $aluno->numero_matricula);
        $this->assertSame(Estabelecimento::current()->id, $aluno->estabelecimento_id);

        $pessoa = $aluno->dadosPessoa;
        $this->assertSame('Ana Silva', $pessoa->nome_completo);
        $this->assertSame('BI0001', $pessoa->numero_identificacao);
        $this->assertSame(DadosPessoal::TIPO_ALUNO, $pessoa->tipo_pessoa);
    }

    public function test_cria_aluno_reutilizando_dados_pessoa_existente(): void
    {
        $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Bruno Costa',
            'numero_identificacao' => 'BI0002',
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        $dto = new AlunoDTO(
            dadosPessoaId: $pessoa->id,
            nomeCompleto: null,
            email: null,
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: null,
        );

        $aluno = app(CriarAlunoAction::class)->executar($dto);

        $this->assertSame($pessoa->id, $aluno->dados_pessoa_id);
        $this->assertSame(1, DadosPessoal::count());
    }

    public function test_numeros_de_matricula_gerados_sao_sequenciais_e_unicos(): void
    {
        $this->criarEstabelecimento();

        $primeiro = app(CriarAlunoAction::class)->executar(new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva',
            email: null,
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: 'BI0001',
        ));

        $segundo = app(CriarAlunoAction::class)->executar(new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Bruno Costa',
            email: null,
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: 'BI0002',
        ));

        $this->assertNotSame($primeiro->numero_matricula, $segundo->numero_matricula);
    }
}
