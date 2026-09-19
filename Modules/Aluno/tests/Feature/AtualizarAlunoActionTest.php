<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Aluno\Actions\AtualizarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class AtualizarAlunoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_actualiza_dados_pessoa_sem_alterar_numero_matricula(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $dto = new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva Santos',
            email: 'ana.santos@example.com',
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: 'BI0001',
        );

        $actualizado = (new AtualizarAlunoAction())->executar($aluno, $dto);

        $this->assertSame('2026-0001', $actualizado->numero_matricula, 'numero_matricula e permanente, nunca deve mudar na edicao');
        $this->assertSame('Ana Silva Santos', $actualizado->dadosPessoa->fresh()->nome_completo);
        $this->assertSame('ana.santos@example.com', $actualizado->dadosPessoa->fresh()->email);
    }

    public function test_actualiza_telefone_alternativo(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $dto = new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva',
            email: null,
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: 'BI0001',
            telefoneAlternativo: '924000000',
        );

        $actualizado = (new AtualizarAlunoAction())->executar($aluno, $dto);

        $this->assertSame('924000000', $actualizado->dadosPessoa->fresh()->telefone_alternativo);
    }

    public function test_actualiza_foto_substituindo_a_anterior(): void
    {
        Storage::fake('public');
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $fotoAntiga = UploadedFile::fake()->image('antiga.jpg')->store('alunos/fotos', 'public');
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001', 'foto_path' => $fotoAntiga]);

        $dto = new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva',
            email: null,
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: 'BI0001',
        );
        $fotoNova = UploadedFile::fake()->image('nova.jpg');

        $actualizado = (new AtualizarAlunoAction())->executar($aluno, $dto, $fotoNova);

        Storage::disk('public')->assertMissing($fotoAntiga);
        Storage::disk('public')->assertExists($actualizado->foto_path);
        $this->assertNotSame($fotoAntiga, $actualizado->foto_path);
    }
}
