<?php

namespace Modules\Licenciamento\Enums;

enum ModoDeOperacao: string
{
    case NORMAL = 'normal';
    case AVISO = 'aviso';
    case BLOQUEADO = 'bloqueado';
}
