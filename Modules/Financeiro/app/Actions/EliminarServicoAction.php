<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Support\ViolacaoDeChave;
use Modules\Core\Traits\LogService;

class EliminarServicoAction
{
    use LogService;

    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Servico $servico): void
    {
        $this->comLog('Eliminar serviço', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($servico));
    }

    private function executarSemRegisto(Servico $servico): void
    {
        if ($this->referencias->existeReferenciaA($servico)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este serviço: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        ViolacaoDeChave::comoValidacao(function () use ($servico) {
            CatalogoAlvo::query()->where('item_tipo', 'servico')->where('item_id', $servico->id)->delete();
            $servico->delete();
        });
    }
}
