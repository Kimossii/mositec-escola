<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Actions\AlterarEstadoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Core\Licenciamento\Exceptions\LimiteDeLicencaExcedido;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Tests\Support\ComLicencaDeTeste;
use Modules\Usuario\Models\DadosPessoa;
use Tests\TestCase;

class LimiteDeAlunosTest extends TestCase
{
    use ComLicencaDeTeste;
    use RefreshDatabase;

    private function dto(string $bi): AlunoDTO
    {
        return new AlunoDTO(null, "Aluno {$bi}", null, null, '2010-05-01', DadosPessoa::SEXO_FEMININO, $bi);
    }

    public function test_criar_aluno_acima_do_limite_falha_e_nao_grava(): void
    {
        $this->prepararLicencas();
        $this->congelarRelogioDeTeste();
        $this->estabelecimentoDeTeste();
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $this->tokenDeTeste(['lim' => ['alunos' => 1, 'utilizadores' => null, 'estabelecimentos' => 1]]));

        app(CriarAlunoAction::class)->executar($this->dto('BI1'));

        try {
            app(CriarAlunoAction::class)->executar($this->dto('BI2'));
            $this->fail('Devia ter excedido o limite.');
        } catch (LimiteDeLicencaExcedido) {
            $this->assertSame(1, Aluno::count());
            $this->assertSame(1, DadosPessoa::where('tipo_pessoa', DadosPessoa::TIPO_ALUNO)->count());
        }
    }

    public function test_sem_licenca_criar_aluno_continua_a_funcionar(): void
    {
        $this->estabelecimentoDeTeste();

        app(CriarAlunoAction::class)->executar($this->dto('BI1'));
        app(CriarAlunoAction::class)->executar($this->dto('BI2'));

        $this->assertSame(2, Aluno::count());
    }

    public function test_aluno_inativo_nao_gasta_vaga_e_reactivar_respeita_o_limite(): void
    {
        $this->prepararLicencas();
        $this->congelarRelogioDeTeste();
        $this->estabelecimentoDeTeste();
        app(InstalarLicencaAction::class)->executar($this->tenant->id, $this->tenant->codigo, $this->tokenDeTeste(['lim' => ['alunos' => 1, 'utilizadores' => null, 'estabelecimentos' => 1]]));

        $primeiro = app(CriarAlunoAction::class)->executar($this->dto('BI1'));
        app(AlterarEstadoAlunoAction::class)->executar($primeiro, Estado::INATIVO);

        // A vaga libertou-se: cria outro.
        app(CriarAlunoAction::class)->executar($this->dto('BI2'));

        // Reactivar o primeiro ultrapassaria o limite.
        $this->expectException(LimiteDeLicencaExcedido::class);
        app(AlterarEstadoAlunoAction::class)->executar($primeiro->fresh(), Estado::ATIVO);
    }
}
