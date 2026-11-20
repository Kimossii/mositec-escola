<?php

namespace Modules\Core\Licenciamento\Enums;

/** Recursos cujo número a licença pode limitar (limites são sempre opcionais). */
enum Recurso: string
{
    case ALUNOS = 'alunos';
    case UTILIZADORES = 'utilizadores';
}
