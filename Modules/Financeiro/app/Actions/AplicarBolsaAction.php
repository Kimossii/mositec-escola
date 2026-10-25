<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Enums\EstadoBolsa;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Models\Matricula;
use Modules\Core\Traits\LogService;

/**
 * Aplica a bolsa às propinas JÁ geradas do aluno nesse ano: só Em Aberto, sem nenhum pagamento, sem bolsa e dentro das
 * datas da bolsa. O valor reajusta-se pelo único escritor (RecalcularPropina) e a propina passa a indicar a bolsa e
 * quanto abateu. Bolsa de 100% não se aplica assim (nada a cobrar): cancelam-se essas propinas à mão.
 */
class AplicarBolsaAction
{
    use LogService;

    public function __construct(private BloqueioDePropinas $bloqueio, private RecalcularPropina $recalcular)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Bolsa $bolsa): array
    {
        return $this->comLog('Aplicar bolsa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($bolsa));
    }

    /** @return array{aplicadas: int, ignoradas: int} */
    private function executarSemRegisto(Bolsa $bolsa): array
    {
        return DB::transaction(function () use ($bolsa) {
            $actual = Bolsa::query()->whereKey($bolsa->id)->lockForUpdate()->firstOrFail();

            if ($actual->estado !== EstadoBolsa::ACTIVA) {
                throw ValidationException::withMessages(['bolsa' => 'Só se aplica uma bolsa activa.']);
            }

            if ($actual->percentagem >= 10000) {
                throw ValidationException::withMessages(['bolsa' => 'Uma bolsa de 100% não se aplica a propinas já geradas: cancele essas propinas.']);
            }

            $ids = Propina::query()
                ->whereIn('matricula_id', Matricula::query()->where('aluno_id', $actual->aluno_id)->select('id'))
                ->where('ano_lectivo_id', $actual->ano_lectivo_id)
                ->where('estado', EstadoCobranca::EM_ABERTO->value)
                ->whereNull('bolsa_id')
                ->whereBetween('periodo_inicio', [$actual->data_inicio->toDateString(), $actual->data_fim->toDateString()])
                ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

            $aplicadas = 0;
            $ignoradas = 0;

            foreach ($this->bloqueio->bloquear($ids) as $propina) {
                // Relida sob bloqueio: um pagamento entretanto confirmado impede a aplicação.
                if ($propina->estado !== EstadoCobranca::EM_ABERTO || $propina->bolsa_id !== null || $propina->valor_pago->unidadesMenores() > 0) {
                    $ignoradas++;

                    continue;
                }

                $valor = $propina->valor->unidadesMenores();
                $desconto = $actual->descontoSobre($valor);

                if ($desconto <= 0 || $desconto >= $valor) {
                    $ignoradas++;

                    continue;
                }

                $this->recalcular->reajustarValor($propina, Dinheiro::deUnidadesMenores($valor - $desconto));
                Propina::query()->whereKey($propina->id)->first()->forceFill(['bolsa_id' => $actual->id, 'valor_bolsa' => $desconto])->save();
                $aplicadas++;
            }

            return ['aplicadas' => $aplicadas, 'ignoradas' => $ignoradas];
        });
    }
}
