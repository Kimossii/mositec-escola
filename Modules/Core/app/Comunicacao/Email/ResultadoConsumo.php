<?php

namespace Modules\Core\Comunicacao\Email;

/** O que a quota mensal decidiu para um email opcional. */
enum ResultadoConsumo
{
    case PERMITIDO;
    case PERMITIDO_NO_AVISO; // este envio cruzou a % de aviso (só uma vez por mês)
    case PERMITIDO_NO_LIMITE; // este envio esgotou a quota
    case BLOQUEADO;
}
