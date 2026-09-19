<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class CriarAlunoRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_falha_sem_nome_e_sem_dados_pessoa_id(): void
    {
        $validator = Validator::make([], (new CriarAlunoRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('nome_completo', $validator->errors()->toArray());
        $this->assertArrayHasKey('numero_identificacao', $validator->errors()->toArray());
    }

    public function test_falha_sem_data_nascimento_ao_criar_pessoa_nova(): void
    {
        $validator = Validator::make([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
        ], (new CriarAlunoRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('data_nascimento', $validator->errors()->toArray());
    }

    public function test_passa_com_data_nascimento_preenchida(): void
    {
        $validator = Validator::make([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
            'data_nascimento' => '2010-05-01',
        ], (new CriarAlunoRequest())->rules());

        $this->assertFalse($validator->fails());
    }

    public function test_nao_aceita_numero_matricula_como_input(): void
    {
        $this->assertArrayNotHasKey('numero_matricula', (new CriarAlunoRequest())->rules());
    }

    public function test_passa_com_dados_pessoa_id_existente(): void
    {
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);

        $validator = Validator::make([
            'dados_pessoa_id' => $pessoa->id,
        ], (new CriarAlunoRequest())->rules());

        $this->assertFalse($validator->fails());
    }
}
