<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoMulta;
use Modules\Financeiro\Enums\OrigemRevisaoMulta;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Models\EscalaoMulta;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Models\PropinaMultaRevisao;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;

/**
 * Aplica as multas por atraso (spec Propinas §15). Determinístico e idempotente por construção: cada propina é
 * avaliada numa transacção própria com a propina bloqueada; a criação é `insertOrIgnore` e a subida é um UPDATE
 * condicional, por isso correr 2× (ou em simultâneo) não duplica nem reverte. Não escreve `valor_pago` (é do
 * RecalcularMulta): apenas cria/sobe `valor`, `escalao_ordem`, `tipo`/`valor_regra` e as revisões automáticas.
 */
class AplicarMultasService
{
    public function __construct(
        private RelogioDoTenant $relogio,
        private BloqueioDePropinas $bloqueio,
        private FontesDePagamentoDePropina $fontes,
        private TenantContext $contexto,
    ) {
    }

    /**
     * @return array{criadas: int, escaloes_subidos: int, ignoradas: int}
     */
    public function executar(): array
    {
        $regras = RegraCobranca::doTenant();

        if (! $regras->multa_activa) {
            return ['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 0];
        }

        $escaloes = $regras->escaloes()->orderBy('ordem')->get();

        if ($escaloes->isEmpty()) {
            return ['criadas' => 0, 'escaloes_subidos' => 0, 'ignoradas' => 0];
        }

        $hoje = $this->relogio->hoje()->toDateString();
        $minDias = $escaloes->min('dias_atraso');
        $maxOrdem = $escaloes->max('ordem');
        // data_limite + min(dias_atraso) ≤ hoje  ⇔  data_limite ≤ hoje − min(dias_atraso).
        $limite = CarbonImmutable::parse($hoje)->subDays($minDias)->toDateString();
        // capital_liquidado_em ≥ L₁ = data_limite + dias do primeiro escalão, na sintaxe de datas do motor.
        // O $minDias vai como binding (whereRaw com '?'), nunca interpolado.
        $condicaoAtraso = match (Propina::query()->getConnection()->getDriverName()) {
            'pgsql' => 'capital_liquidado_em >= data_limite + ?::integer',
            default => 'julianday(capital_liquidado_em) >= julianday(data_limite) + ?',
        };

        // Candidatas filtradas já na query (desempenho): em dívida ou PAGA com capital liquidado a partir de L₁
        // (esteve em atraso; saldo_em(d) usa contribuições ESTRITAMENTE anteriores a d, logo liquidar exactamente
        // em L₁ ainda multa). Sem multa já no último escalão nem bloqueada manualmente. O catch-up continua:
        // Em Aberto/Parcialmente Paga (capital_liquidado_em nulo) e PAGA liquidada a partir de L₁ são candidatas.
        $candidatas = Propina::query()
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value, EstadoCobranca::PAGA->value])
            ->where('data_limite', '<=', $limite)
            ->where(function (Builder $q) use ($condicaoAtraso, $minDias) {
                $q->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
                    ->orWhere(function (Builder $sub) use ($condicaoAtraso, $minDias) {
                        $sub->where('estado', EstadoCobranca::PAGA->value)
                            ->whereRaw($condicaoAtraso, [$minDias]);
                    });
            })
            ->where(function (Builder $q) use ($maxOrdem) {
                $q->doesntHave('multa')
                    ->orWhereHas('multa', fn (Builder $m) => $m->where('escalao_ordem', '<', $maxOrdem)->where('bloqueada_automatico', 0));
            });

        $criadas = 0;
        $subidos = 0;
        $ignoradas = 0;

        foreach ($candidatas->lazyById() as $propina) {
            $resultado = DB::transaction(fn () => $this->aplicar($propina, $escaloes, $hoje));

            match ($resultado) {
                'criada' => $criadas++,
                'subiu' => $subidos++,
                default => $ignoradas++,
            };
        }

        return ['criadas' => $criadas, 'escaloes_subidos' => $subidos, 'ignoradas' => $ignoradas];
    }

    /**
     * @param  Collection<int, EscalaoMulta>  $escaloes  por ordem crescente
     */
    private function aplicar(Propina $propina, Collection $escaloes, string $hoje): string
    {
        $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
            ?? throw new LogicException('A propina a avaliar não existe no tenant corrente.');

        if (in_array($bloqueada->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
            return 'ignorada';
        }

        $contribuicoes = $this->fontes->contribuicoes($bloqueada);
        $aplicaveis = [];

        foreach ($escaloes as $escalao) {
            $L = $bloqueada->data_limite->addDays($escalao->dias_atraso)->toDateString();

            if ($L > $hoje) {
                break;
            }

            $saldo = $this->saldoEm($L, $bloqueada, $contribuicoes);

            if ($saldo <= 0) {
                break; // capital liquidado antes de L_k: os escalões seguintes não se aplicam
            }

            $aplicaveis[] = ['escalao' => $escalao, 'L' => $L, 'saldo' => $saldo];
        }

        if ($aplicaveis === []) {
            return 'ignorada';
        }

        $multa = PropinaMulta::query()->where('propina_id', $bloqueada->getKey())->first();

        if ($multa === null) {
            return $this->criar($bloqueada, $aplicaveis) ? 'criada' : 'ignorada';
        }

        return $this->subir($multa, $aplicaveis) ? 'subiu' : 'ignorada';
    }

    private function saldoEm(string $data, Propina $propina, array $contribuicoes): int
    {
        $saldo = $propina->valor->unidadesMenores();

        foreach ($contribuicoes as $contribuicao) {
            if ($contribuicao->data->toDateString() < $data) {
                $saldo -= $contribuicao->valor->unidadesMenores();
            }
        }

        return max(0, $saldo);
    }

    /**
     * @param  list<array{escalao: EscalaoMulta, L: string, saldo: int}>  $aplicaveis
     */
    private function criar(Propina $propina, array $aplicaveis): bool
    {
        $base = $aplicaveis[0]['saldo'];
        $valor = 0;

        foreach ($aplicaveis as $a) {
            $valor = max($valor, $this->valorDoEscalao($a['escalao'], $base));
        }

        if ($valor <= 0) {
            return false; // decisão 7: percentagem arredonda para baixo; valor 0 não cria multa nem revisão
        }

        $ultimo = end($aplicaveis);
        $agora = $this->instanteDeAuditoria();

        $inserida = PropinaMulta::query()->insertOrIgnore([
            'tenant_id' => $this->contexto->id(),
            'propina_id' => $propina->getKey(),
            'escalao_ordem' => $ultimo['escalao']->ordem,
            'tipo' => $ultimo['escalao']->tipo->value,
            'valor_regra' => $ultimo['escalao']->valor,
            'base_capital' => $base,
            'valor' => $valor,
            'estado' => EstadoMulta::ACTIVA->value,
            'estado_descricao' => EstadoMulta::ACTIVA->label(),
            'bloqueada_automatico' => false,
            'criado_por' => null,
            'editado_por' => null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        if ($inserida === 0) {
            return false; // corrida: outra execução já a criou
        }

        $multa = PropinaMulta::query()->where('propina_id', $propina->getKey())->first();

        $anterior = 0;

        foreach ($aplicaveis as $a) {
            $novo = max($anterior, $this->valorDoEscalao($a['escalao'], $base));
            $this->revisaoAutomatica($multa->getKey(), $a['escalao']->ordem, $anterior, $novo, $a['L']);
            $anterior = $novo;
        }

        return true;
    }

    /**
     * @param  list<array{escalao: EscalaoMulta, L: string, saldo: int}>  $aplicaveis
     */
    private function subir(PropinaMulta $multa, array $aplicaveis): bool
    {
        if ($multa->bloqueada_automatico) {
            return false; // isenção/redução manual: o comando deixa de a alterar
        }

        $base = $multa->base_capital->unidadesMenores();
        $anterior = $multa->valor->unidadesMenores();
        $mudou = false;

        foreach ($aplicaveis as $a) {
            if ($a['escalao']->ordem <= $multa->escalao_ordem) {
                continue;
            }

            $novo = max($anterior, $this->valorDoEscalao($a['escalao'], $base));

            $afectadas = PropinaMulta::query()
                ->whereKey($multa->getKey())
                ->where('escalao_ordem', '<', $a['escalao']->ordem)
                ->where('bloqueada_automatico', false)
                ->update([
                    'escalao_ordem' => $a['escalao']->ordem,
                    'tipo' => $a['escalao']->tipo->value,
                    'valor_regra' => $a['escalao']->valor,
                    'valor' => $novo,
                ]);

            if ($afectadas === 1) {
                $this->revisaoAutomatica($multa->getKey(), $a['escalao']->ordem, $anterior, $novo, $a['L']);
                $anterior = $novo;
                $mudou = true;
            }
        }

        return $mudou;
    }

    private function valorDoEscalao(EscalaoMulta $escalao, int $base): int
    {
        return $escalao->tipo === TipoMulta::PERCENTAGEM
            ? Dinheiro::deUnidadesMenores($base)->pontosBase($escalao->valor)->unidadesMenores()
            : $escalao->valor;
    }

    private function revisaoAutomatica(int $multaId, int $ordem, int $anterior, int $novo, string $dataReferencia): void
    {
        PropinaMultaRevisao::query()->insertOrIgnore([
            'tenant_id' => $this->contexto->id(),
            'propina_multa_id' => $multaId,
            'origem' => OrigemRevisaoMulta::AUTOMATICA->value,
            'escalao_ordem' => $ordem,
            'valor_anterior' => $anterior,
            'valor_novo' => $novo,
            'data_referencia' => $dataReferencia,
            'motivo' => null,
            'criado_por' => null,
            'created_at' => $this->instanteDeAuditoria(),
        ]);
    }

    /** O instante de auditoria, na zona da aplicação (como os created_at do Eloquent). */
    private function instanteDeAuditoria(): string
    {
        return $this->relogio->agora()->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
