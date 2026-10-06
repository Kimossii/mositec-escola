<?php

namespace Modules\Permissao\Exceptions;

use RuntimeException;

/** Regra: a conta de aluno só usa as permissões do perfil de aluno. */
class PerfilDeAlunoFixo extends RuntimeException
{
    public static function semPermissoesPersonalizadas(): self
    {
        return new self('Utilizadores aluno usam apenas as permissões do perfil de aluno.');
    }

    public static function semPerfisExtra(): self
    {
        return new self('Um utilizador aluno não pode ter outros perfis, nem o perfil de aluno pode ser atribuído a outros utilizadores.');
    }
}
