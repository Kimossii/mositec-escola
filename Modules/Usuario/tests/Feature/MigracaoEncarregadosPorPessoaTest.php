<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use RuntimeException;
use Tests\TestCase;

/** As ligações antigas (encarregado → conta do aluno) passam a apontar para a ficha (pessoa) desse aluno. */
class MigracaoEncarregadosPorPessoaTest extends TestCase
{
    use DatabaseMigrations;

    private const FICHEIROS = [
        '2026_11_26_100000_add_dados_pessoa_id_to_encarregados_alunos_table.php',
        '2026_11_26_100100_preencher_dados_pessoa_id_em_encarregados_alunos.php',
        '2026_11_26_100200_trocar_aluno_id_por_dados_pessoa_id_em_encarregados_alunos.php',
    ];

    private function caminhos(): array
    {
        return array_map(fn (string $f) => 'Modules/Usuario/database/migrations/' . $f, self::FICHEIROS);
    }

    private function voltarAoEsquemaAntigo(): void
    {
        $this->artisan('migrate:rollback', ['--path' => $this->caminhos(), '--force' => true])->assertExitCode(0);
    }

    private function migrarOEsquemaNovo(): int
    {
        return $this->artisan('migrate', ['--path' => $this->caminhos(), '--force' => true])->run();
    }

    private function ligacaoAntiga(?int $pessoaDaConta): array
    {
        $enc = User::create(['name' => 'Pai', 'email' => 'pai@example.com', 'password' => Hash::make('x')]);
        $aluno = User::create(['name' => 'Filho', 'numero_matricula' => '2026-0001', 'password' => Hash::make('x'), 'dados_pessoa_id' => $pessoaDaConta]);

        DB::table('encarregados_alunos')->insert([
            'tenant_id' => $this->tenant->id, 'encarregado_id' => $enc->id, 'aluno_id' => $aluno->id, 'parentesco' => 'Pai',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$enc->id, $aluno->id];
    }

    public function test_esquema_antigo_e_novo_trocam_aluno_id_por_dados_pessoa_id(): void
    {
        $this->assertTrue(Schema::hasColumn('encarregados_alunos', 'dados_pessoa_id'));
        $this->assertFalse(Schema::hasColumn('encarregados_alunos', 'aluno_id'));

        $this->voltarAoEsquemaAntigo();

        $this->assertTrue(Schema::hasColumn('encarregados_alunos', 'aluno_id'));
        $this->assertFalse(Schema::hasColumn('encarregados_alunos', 'dados_pessoa_id'));
    }

    public function test_a_ligacao_antiga_passa_a_apontar_para_a_pessoa_da_conta_do_aluno(): void
    {
        $pessoa = DadosPessoa::create(['nome_completo' => 'Filho', 'numero_identificacao' => 'BI-1', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $this->voltarAoEsquemaAntigo();
        [$encId] = $this->ligacaoAntiga($pessoa->id);

        $this->assertSame(0, $this->migrarOEsquemaNovo());

        $linha = DB::table('encarregados_alunos')->where('encarregado_id', $encId)->first();
        $this->assertSame($pessoa->id, (int) $linha->dados_pessoa_id);
        $this->assertSame('Pai', $linha->parentesco);
    }

    public function test_conta_sem_ficha_trava_a_migracao_em_vez_de_perder_a_ligacao(): void
    {
        $this->voltarAoEsquemaAntigo();
        $this->ligacaoAntiga(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conta sem ficha de aluno');

        $this->migrarOEsquemaNovo();
    }
}
