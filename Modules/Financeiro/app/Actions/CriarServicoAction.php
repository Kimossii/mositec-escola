<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ServicoDTO;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;

class CriarServicoAction
{
    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    public function executar(ServicoDTO $dto): Servico
    {
        $item = Servico::create([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ] + $dto->extras['atributos']);

        $this->detalhes->executar($item, $dto->extras);

        return $item;
    }
}
