<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\ServicoDTO;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\SincronizaDetalhesDoCatalogo;
use Modules\Core\Traits\LogService;

class CriarServicoAction
{
    use LogService;

    public function __construct(private SincronizaDetalhesDoCatalogo $detalhes)
    {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(ServicoDTO $dto): Servico
    {
        return $this->comLog('Criar serviço', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(ServicoDTO $dto): Servico
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
