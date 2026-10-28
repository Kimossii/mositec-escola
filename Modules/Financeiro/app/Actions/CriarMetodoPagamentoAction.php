<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Core\Traits\LogService;

class CriarMetodoPagamentoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(MetodoPagamentoDTO $dto): MetodoPagamento
    {
        return $this->comLog('Criar método pagamento', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(MetodoPagamentoDTO $dto): MetodoPagamento
    {
        return MetodoPagamento::create([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo,
            'titular' => $dto->titular,
            'banco' => $dto->banco,
            'iban' => $dto->iban,
            'numero_conta' => $dto->numeroConta,
            'telefone' => $dto->telefone,
            'exige_comprovativo' => $dto->exigeComprovativo,
        ]);
    }
}
