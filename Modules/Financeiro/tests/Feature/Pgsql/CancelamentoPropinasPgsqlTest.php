<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * D5 do plano F3, provado com duas sessões reais: ordem matrícula → propinas; o cancelamento manual só bloqueia a
 * propina; a mudança de estado e a edição seguram a matrícula até ao commit. SQLite ignora FOR UPDATE.
 */
#[Group('pgsql')]
class CancelamentoPropinasPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    /** SQLSTATE da falha de uma consulta na outra ligação; null se correu (55P03 = esperou mais do que o lock_timeout). */
    private function sqlstateNaOutraLigacao(string $sql, array $valores): ?string
    {
        try {
            $this->outraLigacao()->select($sql, $valores);

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    public function test_o_cancelamento_manual_bloqueia_a_propina_ate_ao_commit(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 3);
        $bloqueio = 'nao-verificado';
        Propina::updating(function (Propina $actual) use ($propina, &$bloqueio) {
            if ($actual->id === $propina->id && $bloqueio === 'nao-verificado') {
                $bloqueio = $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$propina->id]);
            }
        });

        app(CancelarPropinaAction::class)->executar($propina, new CancelarPropinaDTO('Motivo de teste'), null);

        $this->assertSame('55P03', $bloqueio, 'Outra sessão (ex.: um pagamento) tem de esperar pelo cancelamento.');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$propina->id]));
        $this->assertSame(4, (int) $this->outraLigacao()->selectOne('select estado from propinas where id = ?', [$propina->id])->estado);
    }

    public function test_a_mudanca_de_estado_segura_a_matricula_e_as_propinas_que_cancela_ate_ao_commit(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(1, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        $junho = Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 10)->firstOrFail();
        $matriculaBloqueada = 'nao-verificado';
        $propinaBloqueada = 'nao-verificado';
        // Registado depois do listener do Financeiro: corre a seguir ao cancelamento, ainda na transacção.
        Event::listen(MatriculaEstadoAlterado::class, function () use ($matricula, $junho, &$matriculaBloqueada, &$propinaBloqueada) {
            $matriculaBloqueada = $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for key share', [$matricula->id]);
            $propinaBloqueada = $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$junho->id]);
        });

        // O1: data de fim passada e ≥ data da matrícula (o relógio não está congelado); Nov–Jun começam depois → 8.
        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA, null, '2026-10-01');

        $this->assertSame('55P03', $matriculaBloqueada, 'Inserir propinas desta matrícula noutra sessão (KEY SHARE) tem de esperar.');
        $this->assertSame('55P03', $propinaBloqueada);
        $this->assertSame(8, (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where matricula_id = ? and estado = 4', [$matricula->id])->n);
    }

    public function test_a_edicao_bloqueia_a_matricula_antes_de_consultar_as_dependencias(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $bloqueio = 'nao-verificado';
        $verificar = function () use ($matricula, &$bloqueio) {
            $bloqueio = $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for key share', [$matricula->id]);
        };
        $dependencia = new class ($verificar) implements DependenciasDaMatricula {
            public function __construct(private Closure $verificar)
            {
            }

            public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
            {
                ($this->verificar)();

                return null;
            }
        };
        $this->app->instance('dependencia.pgsql', $dependencia);
        $this->app->tag(['dependencia.pgsql'], DependenciasRegistadasDaMatricula::ETIQUETA);

        app(AtualizarMatriculaAction::class)->executar($matricula, new MatriculaDTO(
            turmaId: $matricula->turma_id,
            anoLectivoId: $matricula->ano_lectivo_id,
            dataMatricula: '2026-09-15',
            estado: null,
            observacoes: null,
        ));

        $this->assertSame('55P03', $bloqueio, 'A geração (que precisa de KEY SHARE/FOR UPDATE na matrícula) não se pode intercalar.');
    }

    public function test_a_geracao_nao_bloqueia_as_propinas_existentes_o_cancelamento_manual_nao_espera(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $existente = $this->propina($matricula, $plano, 1);
        $bloqueio = 'nao-verificado';
        Propina::creating(function () use ($existente, &$bloqueio) {
            if ($bloqueio === 'nao-verificado') {
                $bloqueio = $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$existente->id]);
            }
        });

        $resultado = app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ));

        $this->assertSame(9, $resultado->totais()['criadas']);
        $this->assertNull($bloqueio, 'A geração segura a matrícula, não as propinas existentes: um cancelamento manual não espera por ela (D5, sem ciclo).');
    }
}
