<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Core\Traits\LogService;

class AtualizarMetodoPagamentoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(MetodoPagamento $metodo, MetodoPagamentoDTO $dto): MetodoPagamento
    {
        return $this->comLog('Atualizar método pagamento', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($metodo, $dto));
    }

    private function executarSemRegisto(MetodoPagamento $metodo, MetodoPagamentoDTO $dto): MetodoPagamento
    {
        $metodo->update([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo,
            'titular' => $dto->titular,
            'banco' => $dto->banco,
            'iban' => $dto->iban,
            'numero_conta' => $dto->numeroConta,
            'telefone' => $dto->telefone,
            'exige_comprovativo' => $dto->exigeComprovativo,
        ]);

        return $metodo->fresh();
    }
}
