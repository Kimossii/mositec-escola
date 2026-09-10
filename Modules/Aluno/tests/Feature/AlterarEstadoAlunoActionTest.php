<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\AlterarEstadoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class AlterarEstadoAlunoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_altera_estado_do_aluno(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $actualizado = (new AlterarEstadoAlunoAction())->executar($aluno, Estado::INATIVO);

        $this->assertSame(Estado::INATIVO->value, $actualizado->estado);
        $this->assertSame('Inativo', $actualizado->estado_descricao);
    }
}
