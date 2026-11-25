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

    public static function encarregadoSemPermissoesPersonalizadas(): self
    {
        return new self('Utilizadores encarregado usam apenas as permissões do seu perfil.');
    }

    public static function encarregadoSemPerfisExtra(): self
    {
        return new self('Um utilizador encarregado não pode ter outros perfis, nem o perfil de encarregado pode ser atribuído a outros utilizadores.');
    }

    public static function semPerfisExtra(): self
    {
        return new self('Um utilizador aluno não pode ter outros perfis, nem o perfil de aluno pode ser atribuído a outros utilizadores.');
    }
}
