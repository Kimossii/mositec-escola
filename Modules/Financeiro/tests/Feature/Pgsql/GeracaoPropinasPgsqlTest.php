<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Closure;
use Illuminate\Database\QueryException;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Turma\Models\Turma;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * O que só o PostgreSQL prova na geração (D2, L4, L15): os bloqueios da matrícula e dos planos são
 * mesmo tomados e seguram outra sessão até ao commit; os índices parciais recusam duplicados vindos de
 * outra ligação; uma violação reverte só a matrícula que falhou e a ligação continua utilizável.
 */
#[Group('pgsql')]
class GeracaoPropinasPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    private function gerar(Turma $turma): ResultadoGeracaoPropinasDTO
    {
        return app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: (int) $turma->ano_lectivo_id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: (int) $turma->id,
        ));
    }

    /** SQLSTATE da falha de uma consulta na outra ligação; null se correu. */
    private function sqlstateNaOutraLigacao(string $sql, array $valores): ?string
    {
        try {
            $this->outraLigacao()->select($sql, $valores);

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    /** Corre $verificacao uma vez, quando a geração vai gravar a primeira propina (transacção aberta, bloqueios tomados). */
    private function aoGravarAPrimeira(Closure $verificacao): void
    {
        $feito = false;

        Propina::creating(function () use (&$feito, $verificacao) {
            if ($feito) {
                return;
            }

            $feito = true;
            $verificacao();
        });
    }

    private function contarNaOutraLigacao(int $matriculaId): int
    {
        return (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where matricula_id = ?', [$matriculaId])->n;
    }

    public function test_a_geracao_bloqueia_a_matricula_ate_ao_commit(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $bloqueioDaMatricula = 'nao-verificado';
        $this->aoGravarAPrimeira(function () use ($matricula, &$bloqueioDaMatricula) {
            $bloqueioDaMatricula = $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]);
        });

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);

        $this->assertSame('55P03', $bloqueioDaMatricula, 'Outra sessão devia esperar pelo FOR UPDATE da matrícula (lock_timeout).');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]), 'Depois do commit o bloqueio é libertado.');
        $this->assertSame(10, $this->contarNaOutraLigacao($matricula->id));
    }

    public function test_os_planos_ficam_em_modo_partilhado_a_divisao_espera_outra_geracao_nao(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $exclusivo = 'nao-verificado';
        $partilhado = 'nao-verificado';
        $this->aoGravarAPrimeira(function () use ($plano, &$exclusivo, &$partilhado) {
            $exclusivo = $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$plano->id]);
            $partilhado = $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for share', [$plano->id]);
        });

        $this->gerar($turma);

        $this->assertSame('55P03', $exclusivo, 'A divisão de um plano (FOR UPDATE, F4) tem de esperar pela geração (L15).');
        $this->assertNull($partilhado, 'Outra geração (FOR SHARE) não fica bloqueada nos planos.');
    }

    public function test_duplicados_de_outra_ligacao_sao_recusados_e_a_segunda_execucao_nao_cria_nada(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->gerar($turma);
        $linha = (array) $this->outraLigacao()->selectOne('select * from propinas where matricula_id = ? order by periodo_inicio limit 1', [$matricula->id]);
        unset($linha['id']);

        try {
            $this->outraLigacao()->table('propinas')->insert($linha);
            $this->fail('O índice único parcial devia recusar a segunda propina activa do mesmo período.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0] ?? null);
            $this->assertTrue(
                str_contains($e->getMessage(), 'propinas_plano_ordem_activo_unique') || str_contains($e->getMessage(), 'propinas_periodo_activo_unique'),
                'A mensagem real do PostgreSQL tem de nomear um dos índices únicos parciais de propinas: '.$e->getMessage(),
            );
            $this->assertTrue(ViolacaoDeUnicidade::dasPropinas($e));
        }

        $segunda = $this->gerar($turma);

        $this->assertSame(0, $segunda->totais()['criadas']);
        $this->assertSame(10, $segunda->totais()['ignoradas']);
        $this->assertSame(10, $this->contarNaOutraLigacao($matricula->id));
    }

    public function test_uma_violacao_reverte_so_a_matricula_que_falhou_e_a_ligacao_continua_utilizavel(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $concorrente = false;
        Propina::creating(function (Propina $propina) use ($plano, $primeira, &$concorrente) {
            if ($concorrente || $propina->matricula_id !== $primeira->id || $propina->ordem !== 2) {
                return;
            }

            $concorrente = true;
            $this->propina($primeira, $plano, 2); // mesma sessão: a geração colide com ela no índice parcial
        });

        $resultado = $this->gerar($turma);

        $this->assertSame(1, $resultado->totais()['erros']);
        $this->assertSame(0, $this->contarNaOutraLigacao($primeira->id), 'Nada da matrícula que falhou ficou confirmado.');
        $this->assertSame(10, $this->contarNaOutraLigacao($segunda->id), 'A matrícula seguinte correu numa transacção nova e confirmou.');

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame(10, $this->contarNaOutraLigacao($primeira->id));
    }
}
