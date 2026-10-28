<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\AnulacaoDePropinas;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Core\Traits\LogService;

/**
 * Anulação manual de uma propina (spec Propinas §8, `propina.anular`, motivo obrigatório). Bloqueia SÓ a propina
 * (como o cancelamento, F3 D5) e decide sobre a linha relida e bloqueada.
 */
class AnularPropinaAction
{
    use LogService;

    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private AnulacaoDePropinas $anulacao,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Propina $propina, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): Propina
    {
        return $this->comLog('Anular propina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($propina, $dto, $utilizadorId));
    }

    private function executarSemRegisto(Propina $propina, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): Propina
    {
        return DB::transaction(function () use ($propina, $dto, $utilizadorId) {
            $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
                ?? throw new LogicException('A propina a anular não existe no tenant corrente.');

            if (($razao = $this->anulacao->motivoParaNaoAnular($bloqueada)) !== null) {
                throw ValidationException::withMessages(['propina' => $razao]);
            }

            return $this->anulacao->anular($bloqueada, $dto->motivo, $utilizadorId);
        });
    }
}
