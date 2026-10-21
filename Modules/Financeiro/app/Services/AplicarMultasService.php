<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
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
        $criadas = 0;
        $subidos = 0;
        $ignoradas = 0;

        foreach ($this->candidatasQuery($escaloes, $hoje)->lazyById() as $propina) {
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
     * Aplica as multas devidas às propinas de UMA matrícula (Multa automática no pagamento). Corre dentro da
     * transacção do chamador (RegistarPagamentoAction já bloqueou a matrícula) e NÃO abre transacção própria:
     * cada propina é avaliada e gravada pelo mesmo `aplicar()` idempotente do comando das 02:30. Só actua com
     * `multa_activa` ligado.
     *
     * @return array{criadas: int, escaloes_subidos: int, ignoradas: int}
     */
    public function executarParaMatricula(int $matriculaId): array
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
        $criadas = 0;
        $subidos = 0;
        $ignoradas = 0;

        foreach ($this->candidatasQuery($escaloes, $hoje)->where('matricula_id', $matriculaId)->lazyById() as $propina) {
            $resultado = $this->aplicar($propina, $escaloes, $hoje);

            match ($resultado) {
                'criada' => $criadas++,
                'subiu' => $subidos++,
                default => $ignoradas++,
            };
        }

        return ['criadas' => $criadas, 'escaloes_subidos' => $subidos, 'ignoradas' => $ignoradas];
    }

    /**
     * O valor final de multa (em unidades menores) que `aplicar()` deixaria HOJE numa propina, sem gravar nada.
     * Devolve null quando nada mudaria (sem escalão aplicável, valor 0, ou multa já bloqueada manualmente). É o
     * MESMO cálculo do `aplicar()` real, partilhado pela pré-visualização do pagamento (critério: a prévia e o
     * resultado nunca diferem). Só lê.
     */
    public function multaQueAplicaria(Propina $propina, string $hoje, Collection $escaloes): ?int
    {
        if (in_array($propina->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true) || $this->matriculaTrancada($propina)) {
            return null;
        }

        $aplicaveis = $this->escaloesAplicaveis($propina, $escaloes, $hoje, $this->fontes->contribuicoes($propina));

        if ($aplicaveis === []) {
            return null;
        }

        $multa = PropinaMulta::query()->where('propina_id', $propina->getKey())->first();

        return $this->valorResultante($multa, $aplicaveis);
    }

    /**
     * O escalão mais alto atingido HOJE por uma propina (o mesmo que ficaria em `escalao_ordem` se a multa
     * fosse aplicada agora), sem gravar nada. null = nenhum escalão aplicável (ou propina cancelada/anulada).
     * Usa o mesmo {@see escaloesAplicaveis()} do `multaQueAplicaria()` real, por isso nunca difere da regra gravada.
     */
    public function escalaoAtingido(Propina $propina, string $hoje, Collection $escaloes): ?EscalaoMulta
    {
        if (in_array($propina->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
            return null;
        }

        $aplicaveis = $this->escaloesAplicaveis($propina, $escaloes, $hoje, $this->fontes->contribuicoes($propina));

        return $aplicaveis === [] ? null : $aplicaveis[array_key_last($aplicaveis)]['escalao'];
    }

    /**
     * Se HOJE nasceria multa numa propina ainda por gerar (nasce Em Aberto, capital por inteiro, sem
     * pagamentos). Mais barato que `multaQueAplicaria()`: só compara a data-limite com o primeiro escalão,
     * sem consultar a multa nem as fontes — o suficiente para o aviso «inclui multas estimadas» quando há
     * muitas matrículas. Só lê.
     */
    public function gerariaMultaHoje(string $dataLimite, string $hoje, Collection $escaloes): bool
    {
        if ($escaloes->isEmpty()) {
            return false;
        }

        // Primeiro escalão por ordem (o mesmo critério do escaloesAplicaveis, que pára no primeiro ainda não atingido).
        return CarbonImmutable::parse($dataLimite)->addDays($escaloes->first()->dias_atraso)->toDateString() <= $hoje;
    }

    /**
     * Candidatas a multa, filtradas já na query (desempenho). Assumem `multa_activa` ligado e escalões não vazios.
     */
    private function candidatasQuery(Collection $escaloes, string $hoje): Builder
    {
        // data_limite + min(dias_atraso) ≤ hoje  ⇔  data_limite ≤ hoje − min(dias_atraso).
        $minDias = $escaloes->min('dias_atraso');
        $maxOrdem = $escaloes->max('ordem');
        $limite = CarbonImmutable::parse($hoje)->subDays($minDias)->toDateString();
        // capital_liquidado_em ≥ L₁ = data_limite + dias do primeiro escalão, na sintaxe de datas do motor.
        // O $minDias vai como binding (whereRaw com '?'), nunca interpolado.
        $condicaoAtraso = match (Propina::query()->getConnection()->getDriverName()) {
            'pgsql' => 'capital_liquidado_em >= data_limite + ?::integer',
            default => 'julianday(capital_liquidado_em) >= julianday(data_limite) + ?',
        };

        // Em dívida ou PAGA com capital liquidado a partir de L₁ (esteve em atraso; saldo_em(d) usa contribuições
        // ESTRITAMENTE anteriores a d, logo liquidar exactamente em L₁ ainda multa). Sem multa já no último escalão
        // nem bloqueada manualmente. O catch-up continua: Em Aberto/Parcialmente Paga (capital_liquidado_em nulo) e
        // PAGA liquidada a partir de L₁ são candidatas.
        return Propina::query()
            // Matrículas trancadas têm as multas congeladas (nenhuma nova até reactivar).
            ->whereDoesntHave('matricula', fn (Builder $m) => $m->where('estado', EstadoMatriculaEnum::TRANCADA->value))
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
    }

    /**
     * @param  Collection<int, EscalaoMulta>  $escaloes  por ordem crescente
     */
    /** Multas congeladas enquanto a matrícula está trancada. */
    private function matriculaTrancada(Propina $propina): bool
    {
        return $propina->matricula()->where('estado', EstadoMatriculaEnum::TRANCADA->value)->exists();
    }

    private function aplicar(Propina $propina, Collection $escaloes, string $hoje): string
    {
        $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
            ?? throw new LogicException('A propina a avaliar não existe no tenant corrente.');

        if (in_array($bloqueada->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true) || $this->matriculaTrancada($bloqueada)) {
            return 'ignorada';
        }

        $aplicaveis = $this->escaloesAplicaveis($bloqueada, $escaloes, $hoje, $this->fontes->contribuicoes($bloqueada));

        if ($aplicaveis === []) {
            return 'ignorada';
        }

        $multa = PropinaMulta::query()->where('propina_id', $bloqueada->getKey())->first();

        if ($multa === null) {
            return $this->criar($bloqueada, $aplicaveis) ? 'criada' : 'ignorada';
        }

        return $this->subir($multa, $aplicaveis) ? 'subiu' : 'ignorada';
    }

    /**
     * Escalões atingidos hoje por uma propina, por ordem crescente; pára no primeiro escalão ainda não atingido
     * (L_k > hoje) e no primeiro cujo saldo já era zero em L_k (capital liquidado antes de L_k).
     *
     * @param  Collection<int, EscalaoMulta>  $escaloes  por ordem crescente
     * @param  list<\Modules\Financeiro\Support\ContribuicaoDePagamento>  $contribuicoes
     * @return list<array{escalao: EscalaoMulta, L: string, saldo: int}>
     */
    private function escaloesAplicaveis(Propina $propina, Collection $escaloes, string $hoje, array $contribuicoes): array
    {
        $aplicaveis = [];

        foreach ($escaloes as $escalao) {
            $L = $propina->data_limite->addDays($escalao->dias_atraso)->toDateString();

            if ($L > $hoje) {
                break;
            }

            $saldo = $this->saldoEm($L, $propina, $contribuicoes);

            if ($saldo <= 0) {
                break; // capital liquidado antes de L_k: os escalões seguintes não se aplicam
            }

            $aplicaveis[] = ['escalao' => $escalao, 'L' => $L, 'saldo' => $saldo];
        }

        return $aplicaveis;
    }

    /**
     * O valor final que a multa teria depois de aplicar os escalões atingidos (o mesmo cálculo de criar/subir).
     * null = nada mudaria.
     *
     * @param  list<array{escalao: EscalaoMulta, L: string, saldo: int}>  $aplicaveis
     */
    private function valorResultante(?PropinaMulta $multa, array $aplicaveis): ?int
    {
        if ($multa === null) {
            $base = $aplicaveis[0]['saldo'];
            $valor = 0;

            foreach ($aplicaveis as $a) {
                $valor = max($valor, $this->valorDoEscalao($a['escalao'], $base));
            }

            return $valor <= 0 ? null : $valor; // decisão 7: percentagem arredonda para baixo; valor 0 não cria multa
        }

        if ($multa->bloqueada_automatico) {
            return null; // isenção/redução manual: o comando deixa de a alterar
        }

        $base = $multa->base_capital->unidadesMenores();
        $novo = $multa->valor->unidadesMenores();

        foreach ($aplicaveis as $a) {
            if ($a['escalao']->ordem <= $multa->escalao_ordem) {
                continue;
            }

            $novo = max($novo, $this->valorDoEscalao($a['escalao'], $base));
        }

        return $novo === $multa->valor->unidadesMenores() ? null : $novo;
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
        $valor = $this->valorResultante(null, $aplicaveis);

        if ($valor === null) {
            return false; // decisão 7: percentagem arredonda para baixo; valor 0 não cria multa nem revisão
        }

        $base = $aplicaveis[0]['saldo'];
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

        $anterior = $multa->valor->unidadesMenores();
        $mudou = false;

        foreach ($aplicaveis as $a) {
            if ($a['escalao']->ordem <= $multa->escalao_ordem) {
                continue;
            }

            $novo = max($anterior, $this->valorDoEscalao($a['escalao'], $multa->base_capital->unidadesMenores()));

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
