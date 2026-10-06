<?php

namespace Modules\Core\Tenancy\Enums;

/** Porque é que a recuperação do administrador de uma escola foi recusada (não é persistido). */
enum MotivoRecusaRecuperacao: string
{
    case ESCOLA_NAO_ACTIVA = 'escola_nao_activa';
    case SEM_ADMINISTRADORES = 'sem_administradores';
    /** E-mail que não corresponde a nenhum administrador DESTA escola (inexistente ou de outra). */
    case NAO_ENCONTRADO = 'nao_encontrado';
    case DESACTIVADO = 'desactivado';
    case VARIOS_ADMINISTRADORES = 'varios_administradores';
}
