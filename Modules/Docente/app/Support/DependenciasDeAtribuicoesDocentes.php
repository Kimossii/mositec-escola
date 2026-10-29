<?php

namespace Modules\Docente\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\AnoLectivo\Contracts\DependenciasDoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Docente\Models\AtribuicaoDocente;

/**
 * As atribuições docentes vivem dentro do intervalo do ano lectivo: um ano com atribuições não se
 * elimina, e o início não pode passar a ser posterior ao começo de alguma delas.
 */
class DependenciasDeAtribuicoesDocentes implements DependenciasDoAnoLectivo
{
    /**
     * O novo início é o `data_inicio` que o modelo traz quando a dependência é consultada: a Action atribui
     * em memória o novo início antes de consultar (ainda sem gravar).
     */
    public function bloqueiaAlteracaoDeInicio(AnoLectivo $anoLectivo): ?string
    {
        $inicio = $anoLectivo->data_inicio instanceof DateTimeInterface
            ? $anoLectivo->data_inicio->format('Y-m-d')
            : substr((string) $anoLectivo->data_inicio, 0, 10);

        $primeira = AtribuicaoDocente::query()
            ->where('ano_lectivo_id', $anoLectivo->id)
            ->whereDate('data_inicio', '<', $inicio)
            ->min('data_inicio');

        return $primeira === null
            ? null
            : 'Este Ano Lectivo tem atribuições de docentes que começam em ' . CarbonImmutable::parse(substr((string) $primeira, 0, 10))->format('d/m/Y')
                . ', antes da nova data de início: não é possível mudá-la.';
    }

    public function bloqueiaEliminacao(AnoLectivo $anoLectivo): ?string
    {
        return AtribuicaoDocente::query()->where('ano_lectivo_id', $anoLectivo->id)->exists()
            ? 'Este Ano Lectivo tem atribuições de docentes associadas e não pode ser eliminado.'
            : null;
    }
}
