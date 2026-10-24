<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\AlterarPrecoPlanoDTO;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\DivisaoDePlanoPropina;
use Modules\Financeiro\Support\ReassociacaoDePropinas;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use Modules\Matricula\Models\Matricula;

/**
 * ÚNICA porta de entrada para mudar o preço de um plano com propinas (spec Propinas §14). Uma transacção, tudo ou
 * nada, pela ordem do spec:
 *   1. bloquear, pela ordem global (D7): matrículas das candidatas → plano → propinas candidatas (id crescente);
 *   2. refazer a pré-visualização sobre o estado bloqueado (isolamento por ano, fronteira, âncora e, por decisão do
 *      dono, ANO LECTIVO ENCERRADO: bloqueado também na escrita, re-verificado aqui, depois dos bloqueios) e exigir a MESMA
 *      impressão digital (um pagamento, uma geração, outra alteração ou um 2.º clique pelo meio → recusa);
 *   3–4. encurtar o plano antigo e criar o novo (mesmos alvos e ano), ou mudar o valor in loco;
 *   5. APLICAR: reassociar ao plano novo e reajustar (valor = max(novo, pago), no RecalcularPropina);
 *   6. registar `alteracoes_preco_plano` e um `propina_ajustes` por propina alterada.
 * Nada é apanhado dentro da transacção (PostgreSQL abortá-la-ia); a única conversão de erro é FORA dela. Nenhum
 * efeito fora da BD.
 */
class AlterarPrecoPlanoAction
{
    public const MENSAGEM_DESACTUALIZADA = 'Os dados mudaram desde a pré-visualização (pagamento, anulação, geração ou outra alteração deste plano). Reveja a pré-visualização e confirme de novo.';

    public const MENSAGEM_CONFIRMAR_VALORES = 'Confirme que compreende que alunos da mesma turma vão pagar valores diferentes.';

    public const MENSAGEM_MOTIVO = 'O motivo da alteração de preço deve ter entre 5 e 500 caracteres.';

    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 500;

    public function __construct(
        private AlteracaoPrecoPlanoService $calculo,
        private BloqueioDePropinas $bloqueio,
        private DivisaoDePlanoPropina $divisao,
        private ReassociacaoDePropinas $reassociacao,
        private RecalcularPropina $recalcular,
    ) {
    }

    public function executar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): AlteracaoPrecoPlano
    {
        $tamanhoDoMotivo = mb_strlen(trim((string) $dto->motivo));

        if ($tamanhoDoMotivo < self::MOTIVO_MINIMO || $tamanhoDoMotivo > self::MOTIVO_MAXIMO) {
            throw ValidationException::withMessages(['motivo' => self::MENSAGEM_MOTIVO]);
        }

        if ((string) $dto->impressaoDigital === '') {
            throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
        }

        try {
            return DB::transaction(fn () => $this->gravar($plano, $dto));
        } catch (QueryException $e) {
            // Já FORA da transacção, revertida por inteiro. Só a unicidade do NOME do plano novo é concorrência (o mesmo
            // nome criado ao mesmo tempo): a pré-visualização tem de ser refeita. Qualquer outra violação (ex.:
            // propinas_plano_ordem_activo_unique) é um defeito e propaga.
            if (ViolacaoDeUnicidade::doNomeDoPlano($e)) {
                throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
            }

            throw $e;
        } catch (ValidationException $e) {
            // Já FORA da transacção. A confirmação segue uma pré-visualização aceite com os mesmos parâmetros: recusar
            // agora o valor ou o mês de efeito só acontece se o plano mudou entretanto (ex.: um 2.º clique depois da
            // divisão ou do valor in loco). Isolamento por ano («plano») e M2 mantêm a sua mensagem.
            if (array_intersect(array_keys($e->errors()), ['valor', 'mes_efeito']) !== []) {
                throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
            }

            throw $e;
        }
    }

    private function gravar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): AlteracaoPrecoPlano
    {
        $mesEfeito = $dto->mesEfeito->startOfMonth();
        $dia = $mesEfeito->toDateString();

        // 1. Ordem global de bloqueio (D7): matrículas → plano → propinas. As candidatas lidas aqui sem bloqueio podem
        //    mudar até ao bloqueio do plano; se mudarem, a impressão digital (passo 2) já não coincide.
        $matriculas = Propina::query()->where('plano_propina_id', $plano->getKey())->where('periodo_inicio', '>=', $dia)
            ->distinct()->orderBy('matricula_id')->pluck('matricula_id')->map(fn ($id) => (int) $id)->all();

        if ($matriculas !== []) {
            Matricula::query()->withTrashed()->whereKey($matriculas)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $bloqueado = PlanoPropina::query()->whereKey($plano->getKey())->lockForUpdate()->firstOrFail();
        $this->bloqueio->bloquear(
            Propina::query()->where('plano_propina_id', $bloqueado->id)->where('periodo_inicio', '>=', $dia)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        // 2. O mesmo cálculo da pré-visualização, agora sobre o estado bloqueado. Recusas lançam e revertem.
        $previa = $this->calculo->preVisualizar($bloqueado, $dto);

        if (! hash_equals($previa->impressaoDigital, (string) $dto->impressaoDigital)) {
            throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
        }

        if ($previa->exigeConfirmacaoValoresDiferentes() && ! $dto->confirmaValoresDiferentes) {
            throw ValidationException::withMessages(['confirmar_valores_diferentes' => self::MENSAGEM_CONFIRMAR_VALORES]);
        }

        // 3–4. O plano: dividir em mes_efeito ou mudar o valor in loco.
        $planoNovo = null;

        if ($previa->inLoco) {
            $this->divisao->alterarValor($bloqueado, $dto->valorNovo);
        } else {
            $planoNovo = $this->divisao->dividir($bloqueado, $mesEfeito, $dto->valorNovo, (string) $previa->nomePlanoNovo);
        }

        // 5. As propinas (só APLICAR tem afectadas).
        $ajustes = [];

        // D6: Em Aberto/Parciais que já têm o valor novo só mudam de plano (sem ajuste, sem mexer no valor).
        foreach ($previa->reassociadasSemAjuste as $item) {
            $this->reassociacao->reassociar(Propina::query()->findOrFail($item['propina_id']), $planoNovo, (int) $item['ordem_depois']);
        }

        foreach ($previa->afectadas as $item) {
            $propina = Propina::query()->findOrFail($item['propina_id']);
            $valorAnterior = $propina->valor;
            $planoAnteriorId = (int) $propina->plano_propina_id;

            if ($planoNovo !== null) {
                $propina = $this->reassociacao->reassociar($propina, $planoNovo, (int) $item['ordem_depois']);
            }

            $propina = $this->recalcular->reajustarValor($propina, $dto->valorNovo);

            $ajustes[] = [
                'propina_id' => (int) $propina->id,
                'valor_anterior' => $valorAnterior,
                'valor_novo' => $propina->valor,
                'valor_pago_no_momento' => $propina->valor_pago,
                'plano_anterior_id' => $planoAnteriorId,
                'plano_novo_id' => $planoNovo?->id,
            ];
        }

        // 6. Os registos (só acrescentam).
        $motivo = trim((string) $dto->motivo);
        $alteracao = AlteracaoPrecoPlano::create([
            'ano_lectivo_id' => $bloqueado->ano_lectivo_id,
            'plano_propina_id' => $bloqueado->id,
            'plano_novo_id' => $planoNovo?->id,
            'modo' => $dto->modo,
            'valor_anterior' => Dinheiro::deUnidadesMenores($previa->valorAnterior),
            'valor_novo' => $dto->valorNovo,
            'mes_efeito' => $dia,
            'motivo' => $motivo,
            'total_propinas_afectadas' => count($ajustes),
            'impressao_digital' => $previa->impressaoDigital,
        ]);

        foreach ($ajustes as $ajuste) {
            PropinaAjuste::create($ajuste + ['alteracao_preco_id' => $alteracao->id, 'motivo' => $motivo]);
        }

        $alteracao->totalReassociadasSemAjuste = count($previa->reassociadasSemAjuste);

        return $alteracao->load(['plano', 'planoNovo', 'ajustes']);
    }
}
