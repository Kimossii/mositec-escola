<?php

namespace Modules\Estabelecimento\Services;

use Modules\Core\Tenancy\Contracts\ContactoDaEscola;
use Modules\Estabelecimento\Models\Estabelecimento;

/** Implementação do contrato do Core: o email institucional é o do estabelecimento da escola. */
class ContactoDaEscolaEstabelecimento implements ContactoDaEscola
{
    public function email(): ?string
    {
        $email = trim((string) Estabelecimento::query()->value('email'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function telefone(): ?string
    {
        $telefone = trim((string) Estabelecimento::query()->value('telefone'));

        return $telefone !== '' ? $telefone : null;
    }
}
