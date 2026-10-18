<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;

/**
 * Escritas no PLANO de uma alteração de preço (spec Propinas §14, F4): dividir em `mes_efeito` (o antigo termina no
 * mês anterior; o novo, com os mesmos alvos, o mesmo ano lectivo, a mesma periodicidade e o novo valor, vai até ao
 * fim original) ou mudar o valor in loco (`mes_efeito` = início do plano). Só a AlterarPrecoPlanoAction a chama,
 * dentro da sua transacção e com o plano bloqueado.
 */
final class DivisaoDePlanoPropina
{
    public function __construct(private PlanoPropinaConsultaService $consulta)
    {
    }

    public function dividir(PlanoPropina $bloqueado, CarbonImmutable $mesEfeito, Dinheiro $valorNovo, string $nomeNovo): PlanoPropina
    {
        $this->exigirTransaccao();

        $this->exigirMesDeEfeitoValido($bloqueado, $mesEfeito);

        $dataFimOriginal = $bloqueado->data_fim;
        $alvos = $bloqueado->alvos()->get()
            ->map(fn (PlanoPropinaAlvo $alvo) => $alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id']))
            ->all();

        $bloqueado->update(['data_fim' => $mesEfeito->subDay()->toDateString()]);

        $novo = PlanoPropina::create([
            'ano_lectivo_id' => $bloqueado->ano_lectivo_id,
            'nome' => $nomeNovo,
            'descricao' => $bloqueado->descricao,
            'periodicidade' => $bloqueado->periodicidade,
            'intervalo_meses' => $bloqueado->intervalo_meses,
            'valor' => $valorNovo,
            'data_inicio' => $mesEfeito->toDateString(),
            'data_fim' => $dataFimOriginal->toDateString(),
            'estado' => $bloqueado->estado,
        ]);

        $novo->substituirAlvos($alvos);
        $novo->load(['alvos', 'anoLectivo']);

        // Isolamento por ano lectivo (spec §14): o plano novo nunca é de outro ano. Asserção (o create acima já o garante).
        if ((int) $novo->ano_lectivo_id !== (int) $bloqueado->ano_lectivo_id) {
            throw new LogicException('O plano criado pela divisão tem de ser do mesmo ano lectivo do plano alterado.');
        }

        // O antigo já não cobre estes meses; outro plano com os mesmos alvos neles seria um defeito anterior.
        if ($this->consulta->colisaoDeAlvos((int) $novo->ano_lectivo_id, $alvos, $novo->competencias(), (int) $novo->id)) {
            throw new LogicException('A divisão criaria um plano em colisão com outro plano do mesmo ano lectivo e dos mesmos alvos.');
        }

        return $novo;
    }

    public function alterarValor(PlanoPropina $bloqueado, Dinheiro $valorNovo): PlanoPropina
    {
        $this->exigirTransaccao();

        $bloqueado->update(['valor' => $valorNovo]);

        return $bloqueado;
    }

    /**
     * `mes_efeito` (competência completa: ano e mês) tem de ser o início de um período do plano antigo, estritamente
     * posterior ao primeiro. Nunca se alarga o plano antigo: fora disto a divisão recusa-se antes de qualquer escrita.
     */
    private function exigirMesDeEfeitoValido(PlanoPropina $plano, CarbonImmutable $mesEfeito): void
    {
        $inicios = array_column($plano->periodos(), 'inicio');
        $alvo = $mesEfeito->startOfMonth()->toDateString();

        if ($mesEfeito->day !== 1 || $inicios === [] || $alvo === $inicios[0] || ! in_array($alvo, $inicios, true)) {
            throw new DomainException("O mês de efeito {$mesEfeito->format('Y-m')} tem de ser o início de um período do plano, posterior ao primeiro (períodos: " . implode(', ', array_map(fn (string $i): string => substr($i, 0, 7), $inicios)) . ').');
        }
    }

    private function exigirTransaccao(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Alterar o preço de um plano exige uma transacção aberta (DB::transaction) do chamador.');
        }
    }
}
