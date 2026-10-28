<?php

namespace Modules\Usuario\Actions;

use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Core\Traits\LogService;

class RemoverDocumentoPessoaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(DocumentoPessoa $documento): void
    {
        $this->comLog('Remover documento pessoa', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($documento));
    }

    private function executarSemRegisto(DocumentoPessoa $documento): void
    {
        $documento->delete();
    }
}
