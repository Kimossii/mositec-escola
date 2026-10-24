<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\ResolvePlanoAplicavel;
use Modules\Turma\Models\Turma;

/**
 * Memória do resolvedor de planos durante UMA execução da geração (R6: ResolvePlanoAplicavel carrega
 * todos os planos do ano a cada chamada). A memória só vale enquanto os planos do ano não mudarem:
 * `sincronizar` tira uma impressão (planos + alvos, valores crus) e esquece tudo se ela mudou.
 *
 * Na geração, `sincronizar(..., bloquear: true)` corre dentro da transacção de cada matrícula e bloqueia
 * as linhas dos planos em modo partilhado (L15): a divisão de um plano (F4, lockForUpdate) espera pelo fim
 * dessa transacção, e uma divisão já confirmada muda a impressão. Nunca partilhar uma instância entre
 * tenants nem entre execuções.
 */
final class ResolucaoDePlanosMemorizada
{
    /** @var array<string, ResultadoResolucaoPlano> */
    private array $resolucoes = [];

    /** @var array<int, list<array{ano: int, mes: int}>> */
    private array $meses = [];

    /** @var array<int, string> impressão por ano lectivo */
    private array $impressoes = [];

    public function __construct(private ResolvePlanoAplicavel $resolver)
    {
    }

    public function paraTurma(Turma $turma, int $ano, int $mes): ResultadoResolucaoPlano
    {
        return $this->resolucoes["{$turma->id}:{$ano}:{$mes}"] ??= $this->resolver->paraTurma($turma, ['ano' => $ano, 'mes' => $mes]);
    }

    /**
     * Meses (ano, mês) em que algum plano activo do ano lectivo cobra, por ordem cronológica.
     *
     * @return list<array{ano: int, mes: int}>
     */
    public function mesesCobraveis(int $anoLectivoId): array
    {
        return $this->meses[$anoLectivoId] ??= $this->calcularMeses($anoLectivoId);
    }

    /**
     * Esquece a memória se os planos do ano (ou os seus alvos) mudaram desde a última impressão.
     * Devolve true se esqueceu.
     */
    public function sincronizar(int $anoLectivoId, bool $bloquear): bool
    {
        if ($bloquear && DB::transactionLevel() === 0) {
            throw new LogicException('Bloquear os planos exige uma transacção aberta (DB::transaction) do chamador.');
        }

        $consulta = PlanoPropina::query()->where('ano_lectivo_id', $anoLectivoId)->orderBy('id');

        if ($bloquear) {
            $consulta->sharedLock();
        }

        $planos = $consulta->get();
        $alvos = PlanoPropinaAlvo::query()->whereIn('plano_propina_id', $planos->modelKeys())->orderBy('id')->get();

        $impressao = hash('sha256', json_encode([
            $planos->map(fn (PlanoPropina $plano) => $plano->getAttributes())->all(),
            $alvos->map(fn (PlanoPropinaAlvo $alvo) => $alvo->getAttributes())->all(),
        ], JSON_THROW_ON_ERROR));

        if (($this->impressoes[$anoLectivoId] ?? null) === $impressao) {
            return false;
        }

        $this->resolucoes = [];
        $this->meses = [];
        $this->impressoes[$anoLectivoId] = $impressao;

        return true;
    }

    /**
     * @return list<array{ano: int, mes: int}>
     */
    private function calcularMeses(int $anoLectivoId): array
    {
        $porOrdinal = [];

        foreach (PlanoPropina::query()->activos()->where('ano_lectivo_id', $anoLectivoId)->with('anoLectivo')->get() as $plano) {
            foreach ($plano->competencias() as $competencia) {
                $porOrdinal[$competencia['ano'] * 12 + $competencia['mes']] = $competencia;
            }
        }

        ksort($porOrdinal);

        return array_values($porOrdinal);
    }
}
