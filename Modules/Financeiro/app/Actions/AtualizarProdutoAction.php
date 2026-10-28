<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ProdutoDTO;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;
use Modules\Core\Traits\LogService;

class AtualizarProdutoAction
{
    use LogService;

    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Produto $produto, ProdutoDTO $dto): Produto
    {
        return $this->comLog('Atualizar produto', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($produto, $dto));
    }

    private function executarSemRegisto(Produto $produto, ProdutoDTO $dto): Produto
    {
        $produto->update([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ] + $dto->extras['atributos']);

        $this->detalhes->executar($produto, $dto->extras);

        return $produto->fresh();
    }
}
