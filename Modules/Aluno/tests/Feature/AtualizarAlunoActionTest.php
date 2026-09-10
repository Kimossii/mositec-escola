<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\AtualizarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class AtualizarAlunoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_actualiza_dados_pessoa_sem_alterar_numero_matricula(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $dto = new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva Santos',
            email: 'ana.santos@example.com',
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: null,
        );

        $actualizado = (new AtualizarAlunoAction())->executar($aluno, $dto);

        $this->assertSame('2026-0001', $actualizado->numero_matricula, 'numero_matricula e permanente, nunca deve mudar na edicao');
        $this->assertSame('Ana Silva Santos', $actualizado->dadosPessoa->fresh()->nome_completo);
        $this->assertSame('ana.santos@example.com', $actualizado->dadosPessoa->fresh()->email);
    }
}
