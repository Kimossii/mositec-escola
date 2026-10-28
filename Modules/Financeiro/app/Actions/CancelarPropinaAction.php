<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\CancelamentoDePropinas;
use Modules\Core\Traits\LogService;

/**
 * Cancelamento manual de uma propina (spec §8, permissão `propina.cancelar`, motivo obrigatório). Bloqueia
 * SÓ a propina (D5: nunca a matrícula, por isso não há ciclo com a mudança de estado nem com a geração) e
 * decide sobre a linha relida e bloqueada.
 */
class CancelarPropinaAction
{
    use LogService;

    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private CancelamentoDePropinas $cancelamento,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Propina $propina, CancelarPropinaDTO $dto, ?int $utilizadorId): Propina
    {
        return $this->comLog('Cancelar propina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($propina, $dto, $utilizadorId));
    }

    private function executarSemRegisto(Propina $propina, CancelarPropinaDTO $dto, ?int $utilizadorId): Propina
    {
        return DB::transaction(function () use ($propina, $dto, $utilizadorId) {
            $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
                ?? throw new LogicException('A propina a cancelar não existe no tenant corrente.');

            if (($razao = $this->cancelamento->motivoParaNaoCancelar($bloqueada)) !== null) {
                // Lançada (não apanhada) dentro da transacção: reverte e chega ao utilizador como erro de validação.
                throw ValidationException::withMessages(['propina' => $razao]);
            }

            return $this->cancelamento->cancelar($bloqueada, $dto->motivo, $utilizadorId);
        });
    }
}
