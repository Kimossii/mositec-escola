<?php

namespace Modules\Core\Licenciamento\Contracts;

use Modules\Core\Licenciamento\Enums\Recurso;

/**
 * Contador de um recurso licenciado, implementado pelo módulo dono do recurso (Aluno, Usuario) e registado no
 * container com a etiqueta TAG. Conta apenas o tenant actual (via o escopo de tenancy dos modelos).
 */
interface ContaRecursoLicenciado
{
    public const TAG = 'licenciamento.contadores';

    public function recurso(): Recurso;

    public function contar(): int;
}
