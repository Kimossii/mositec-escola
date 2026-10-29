<?php

namespace Modules\Docente\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Modules\Core\Contracts\DependenciasDoUtilizador;
use Modules\Docente\Models\AtribuicaoDocente;

/**
 * Um professor com atribuições docentes é histórico da pauta: não se elimina (a FK para `users` é restrict).
 * Conta também as eliminadas por soft delete, que continuariam a prender a linha na base de dados.
 */
class DependenciasDoUtilizadorDocente implements DependenciasDoUtilizador
{
    public const MOTIVO = 'Este utilizador tem atribuições de docente registadas e não pode ser eliminado. Para lhe retirar o acesso, desative-o.';

    public function bloqueiaEliminacao(Authenticatable $utilizador): ?string
    {
        return AtribuicaoDocente::query()->withTrashed()->where('professor_id', $utilizador->getAuthIdentifier())->exists()
            ? self::MOTIVO
            : null;
    }
}
