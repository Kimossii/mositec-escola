<?php

namespace Modules\Financeiro\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\IsentarMultaDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Models\AcordoPrestacao;
use Modules\Financeiro\Models\AcordoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Models\Matricula;
use Modules\Core\Traits\LogService;

/**
 * Cria um acordo de pagamento em prestações para as propinas em dívida escolhidas. Só com «permite negociação» ligado
 * e dentro do desconto máximo (em % das multas pendentes, que se reduzem pela acção normal de reduzir multa). Parte o
 * total em prestações iguais, mensais (a última absorve os cêntimos). As propinas passam a aceitar pagamento parcial e
 * as multas ficam congeladas enquanto o acordo estiver activo.
 */
class CriarAcordoAction
{
    use LogService;

    public const MAX_PRESTACOES = 36;

    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private IsentarReduzirMultaAction $reduzirMulta,
        private MoedaDoTenant $moeda,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Matricula $matricula, array $propinaIds, int $descontoMultaPct, int $prestacoes, string $primeiroVencimento, string $motivo, ?int $utilizadorId = null): Acordo
    {
        return $this->comLog('Criar acordo', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula, $propinaIds, $descontoMultaPct, $prestacoes, $primeiroVencimento, $motivo, $utilizadorId));
    }

    /** @param list<int> $propinaIds */
    private function executarSemRegisto(Matricula $matricula, array $propinaIds, int $descontoMultaPct, int $prestacoes, string $primeiroVencimento, string $motivo, ?int $utilizadorId = null): Acordo
    {
        return DB::transaction(function () use ($matricula, $propinaIds, $descontoMultaPct, $prestacoes, $primeiroVencimento, $motivo, $utilizadorId) {
            $regras = RegraCobranca::query()->first();

            if (! ($regras?->permite_negociacao ?? false)) {
                throw ValidationException::withMessages(['acordo' => 'A escola não permite negociação de dívidas (Regras de Cobrança).']);
            }

            if ($descontoMultaPct < 0 || $descontoMultaPct > (int) $regras->desconto_maximo_negociacao) {
                throw ValidationException::withMessages(['desconto_multa_pct' => "O desconto nas multas pode ir de 0 a {$regras->desconto_maximo_negociacao}%."]);
            }

            if ($prestacoes < 1 || $prestacoes > self::MAX_PRESTACOES) {
                throw ValidationException::withMessages(['prestacoes' => 'O número de prestações tem de estar entre 1 e ' . self::MAX_PRESTACOES . '.']);
            }

            $primeiro = CarbonImmutable::parse($primeiroVencimento)->startOfDay();

            if ($primeiro->toDateString() < $this->relogio->hoje()->toDateString()) {
                throw ValidationException::withMessages(['primeiro_vencimento' => 'O primeiro vencimento não pode ser anterior a hoje.']);
            }

            if (mb_strlen(trim($motivo)) < 5) {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do acordo (mínimo 5 caracteres).']);
            }

            $bloqueada = $this->bloqueio->matricula((int) $matricula->getKey(), incluirEliminadas: true)
                ?? throw ValidationException::withMessages(['matricula' => 'A matrícula não existe.']);

            if (Acordo::query()->where('matricula_id', $bloqueada->id)->where('estado', 1)->exists()) {
                throw ValidationException::withMessages(['acordo' => 'Esta matrícula já tem um acordo activo. Cancele-o antes de criar outro.']);
            }

            $ids = array_values(array_unique(array_map('intval', $propinaIds)));
            $propinas = $this->bloqueioDePropinas->bloquear($ids);

            if ($ids === [] || $propinas->count() !== count($ids)) {
                throw ValidationException::withMessages(['propinas' => 'Escolha pelo menos uma propina em dívida desta matrícula.']);
            }

            foreach ($propinas as $propina) {
                $comDivida = $propina->saldo()->unidadesMenores() > 0 || $propina->multaPendente() > 0;

                if ((int) $propina->matricula_id !== (int) $bloqueada->id || ! $comDivida || in_array($propina->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
                    throw ValidationException::withMessages(['propinas' => "A propina {$propina->id} não está em dívida nesta matrícula."]);
                }

                if (AcordoPropina::idsEmAcordoActivo()->where('propina_id', $propina->id)->exists()) {
                    throw ValidationException::withMessages(['propinas' => "A propina {$propina->id} já está noutro acordo activo."]);
                }
            }

            $multaAntes = (int) $propinas->sum(fn (Propina $p) => $p->multaPendente());
            $capital = (int) $propinas->sum(fn (Propina $p) => $p->saldo()->unidadesMenores());

            // Desconto: reduz cada multa pendente em X% (a multa nunca baixa do que já foi pago).
            if ($descontoMultaPct > 0) {
                foreach ($propinas as $propina) {
                    $pendente = $propina->multaPendente();

                    if ($pendente <= 0 || $propina->multa === null) {
                        continue;
                    }

                    $novoValor = $propina->multa->valor->unidadesMenores() - intdiv($pendente * $descontoMultaPct + 50, 100);
                    $this->reduzirMulta->executar($propina, new IsentarMultaDTO(Dinheiro::deUnidadesMenores(max($novoValor, $propina->multa->valor_pago->unidadesMenores())), "Acordo de pagamento: desconto de {$descontoMultaPct}% na multa"), $utilizadorId);
                }
            }

            $propinas = $this->bloqueioDePropinas->bloquear($ids); // relidas após a redução
            $multaDepois = (int) $propinas->sum(fn (Propina $p) => $p->multaPendente());
            $total = $capital + $multaDepois;

            $acordo = Acordo::create([
                'matricula_id' => $bloqueada->id,
                'aluno_id' => $bloqueada->aluno_id,
                'moeda' => $this->moeda->atual()->codigo,
                'divida_inicial' => Dinheiro::deUnidadesMenores($capital + $multaAntes),
                'desconto_multa_pct' => $descontoMultaPct,
                'desconto' => Dinheiro::deUnidadesMenores($multaAntes - $multaDepois),
                'total' => Dinheiro::deUnidadesMenores($total),
                'motivo' => trim($motivo),
            ]);

            foreach ($propinas as $propina) {
                AcordoPropina::create([
                    'acordo_id' => $acordo->id, 'propina_id' => $propina->id,
                    'capital_inicial' => $propina->saldo()->unidadesMenores(), 'multa_inicial' => $propina->multaPendente(),
                ]);
            }

            $base = intdiv($total, $prestacoes);

            for ($i = 1; $i <= $prestacoes; $i++) {
                AcordoPrestacao::create([
                    'acordo_id' => $acordo->id,
                    'ordem' => $i,
                    'data_vencimento' => $primeiro->addMonthsNoOverflow($i - 1)->toDateString(),
                    'valor' => Dinheiro::deUnidadesMenores($i === $prestacoes ? $total - $base * ($prestacoes - 1) : $base),
                ]);
            }

            return $acordo->fresh(['prestacoes', 'propinas']);
        });
    }
}
