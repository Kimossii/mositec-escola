<?php

namespace Modules\Matricula\Contracts;

use Modules\Matricula\Models\Matricula;

/**
 * Implementada por módulos que dependem dos dados académicos de uma matrícula (ex.: propinas geradas para a
 * turma, o ano lectivo e o mês da matrícula) sem que a Matrícula os importe. Devolve o motivo em PT-PT quando a
 * edição deve ser bloqueada (indicando o fluxo correcto), ou null. A matrícula chega bloqueada e ainda com os
 * valores actuais. Registo: $this->app->tag([Classe::class], DependenciasRegistadasDaMatricula::ETIQUETA).
 */
interface DependenciasDaMatricula
{
    public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string;
}
