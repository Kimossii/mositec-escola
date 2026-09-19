<?php

namespace Modules\Usuario\Actions;

use Modules\Usuario\Models\DocumentoPessoa;

class RemoverDocumentoPessoaAction
{
    public function executar(DocumentoPessoa $documento): void
    {
        $documento->delete();
    }
}
