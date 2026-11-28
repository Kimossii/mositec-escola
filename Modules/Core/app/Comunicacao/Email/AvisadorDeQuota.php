<?php

namespace Modules\Core\Comunicacao\Email;

use Illuminate\Support\Facades\Notification;
use Modules\Core\Comunicacao\Email\Notificacoes\QuotaDeEmailEsgotadaNotificacao;
use Modules\Core\Comunicacao\Email\Notificacoes\QuotaDeEmailNoAvisoNotificacao;
use Modules\Core\Licenciamento\Contracts\RegrasDeEmailDaLicenca;
use Modules\Core\Tenancy\Contracts\ContactoDaEscola;

/**
 * Avisa a escola (email institucional) quando a quota mensal chega ao limiar de aviso e quando se esgota, uma só vez por mês cada.
 * Sem email institucional não há aviso por email; o estado fica sempre visível nos ecrãs de consumo. Nunca lança.
 */
class AvisadorDeQuota
{
    public function __construct(
        private RegrasDeEmailDaLicenca $regras,
        private ConsumoDeEmail $consumo,
        private ContactoDaEscola $contacto,
    ) {}

    public function avisar(ResultadoConsumo $resultado): void
    {
        if ($resultado === ResultadoConsumo::PERMITIDO) {
            return;
        }

        $regras = $this->regras->doTenantActual();

        if ($regras->limiteMes === null) {
            return;
        }

        $notificacao = match ($resultado) {
            ResultadoConsumo::PERMITIDO_NO_AVISO => new QuotaDeEmailNoAvisoNotificacao($this->consumo->doMes(), $regras->limiteMes),
            ResultadoConsumo::PERMITIDO_NO_LIMITE => new QuotaDeEmailEsgotadaNotificacao($regras->limiteMes, $regras->bloqueia),
            ResultadoConsumo::BLOQUEADO => $this->consumo->marcarBloqueioAvisado()
                ? new QuotaDeEmailEsgotadaNotificacao($regras->limiteMes, true)
                : null,
        };

        $destino = $this->contacto->email();

        if ($notificacao !== null && $destino !== null) {
            Notification::route('mail', $destino)->notify($notificacao);
        }
    }
}
