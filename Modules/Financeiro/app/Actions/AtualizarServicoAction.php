<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ServicoDTO;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;

class AtualizarServicoAction
{
    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    public function executar(Servico $servico, ServicoDTO $dto): Servico
    {
        $servico->update([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'codigo' => $dto->codigo,
            'preco' => $dto->preco,
        ] + $dto->extras['atributos']);

        $this->detalhes->executar($servico, $dto->extras);

        return $servico->fresh();
    }
}
