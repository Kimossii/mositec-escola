<?php

namespace Modules\Licenciamento\Services;

use Carbon\CarbonImmutable;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\Enums\ModoDeOperacao;

/** Função pura: decide o modo de operação a partir do documento e do instante (UTC). Sem BD nem relógio próprio. */
class AvaliadorDeLicenca
{
    public static function modo(?DireitosDaLicenca $d, CarbonImmutable $agora, int $avisoDiasPadrao, bool $revogada = false): ModoDeOperacao
    {
        if ($d === null || $revogada || $agora->lt($d->inicio)) {
            return ModoDeOperacao::BLOQUEADO;
        }

        // A tolerância conta uma só vez, a partir de expira_em; passado o limite nunca volta atrás.
        if ($agora->gt($d->bloqueiaEm())) {
            return ModoDeOperacao::BLOQUEADO;
        }

        if ($agora->gt($d->expiraEm)) {
            return ModoDeOperacao::AVISO;
        }

        $avisoDias = min(DireitosDaLicenca::MAX_DIAS, max(0, $d->avisoDias ?? $avisoDiasPadrao));

        return $agora->gte($d->expiraEm->subDays($avisoDias)) ? ModoDeOperacao::AVISO : ModoDeOperacao::NORMAL;
    }
}
