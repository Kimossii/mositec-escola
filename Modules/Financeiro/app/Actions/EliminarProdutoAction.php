<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\CatalogoAlvo;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Support\ViolacaoDeChave;
use Modules\Core\Traits\LogService;

class EliminarProdutoAction
{
    use LogService;

    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Produto $produto): void
    {
        $this->comLog('Eliminar produto', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($produto));
    }

    private function executarSemRegisto(Produto $produto): void
    {
        if ($this->referencias->existeReferenciaA($produto)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este produto: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        ViolacaoDeChave::comoValidacao(function () use ($produto) {
            CatalogoAlvo::query()->where('item_tipo', 'produto')->where('item_id', $produto->id)->delete();
            $produto->delete();
        });
    }
}
