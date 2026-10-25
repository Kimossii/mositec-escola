<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ServicoDTO;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;
use Modules\Core\Traits\LogService;

class AtualizarServicoAction
{
    use LogService;

    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Servico $servico, ServicoDTO $dto): Servico
    {
        return $this->comLog('Atualizar serviço', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($servico, $dto));
    }

    private function executarSemRegisto(Servico $servico, ServicoDTO $dto): Servico
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
