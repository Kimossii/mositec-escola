<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ProdutoDTO;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;
use Modules\Core\Traits\LogService;

class CriarProdutoAction
{
    use LogService;

    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(ProdutoDTO $dto): Produto
    {
        return $this->comLog('Criar produto', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(ProdutoDTO $dto): Produto
    {
        $item = Produto::create([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ] + $dto->extras['atributos']);

        $this->detalhes->executar($item, $dto->extras);

        return $item;
    }
}
