<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class AlunoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_apenas_alunos_do_estabelecimento_actual(): void
    {
        $actual = Estabelecimento::create(['nome' => 'Escola Actual', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $outra = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);

        $pessoa1 = DadosPessoal::create(['nome_completo' => 'Ana', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $pessoa2 = DadosPessoal::create(['nome_completo' => 'Bruno', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);

        Aluno::create(['estabelecimento_id' => $actual->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);
        Aluno::create(['estabelecimento_id' => $outra->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);

        $alunos = (new AlunoConsultaService())->listar();

        $this->assertCount(1, $alunos);
        $this->assertSame('2026-0001', $alunos->first()->numero_matricula);
    }
}
