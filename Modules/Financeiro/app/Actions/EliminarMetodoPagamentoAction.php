<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Support\ViolacaoDeChave;
use Modules\Core\Traits\LogService;

class EliminarMetodoPagamentoAction
{
    use LogService;

    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(MetodoPagamento $metodo): void
    {
        $this->comLog('Eliminar método pagamento', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($metodo));
    }

    private function executarSemRegisto(MetodoPagamento $metodo): void
    {
        if ($this->referencias->existeReferenciaA($metodo)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este método de pagamento: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        ViolacaoDeChave::comoValidacao(fn () => $metodo->delete());
    }
}
