<?php

namespace Modules\Autenticacao\Enums;

enum ResultadoConfirmacaoDeEmail: string
{
    case CONFIRMADO = 'confirmado';
    case JA_CONFIRMADO = 'ja_confirmado';
    case INVALIDO = 'invalido';
}
