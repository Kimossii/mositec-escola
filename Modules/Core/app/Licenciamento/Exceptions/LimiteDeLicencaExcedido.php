<?php

namespace Modules\Core\Licenciamento\Exceptions;

use Illuminate\Validation\ValidationException;
use Modules\Core\Licenciamento\Enums\Recurso;

/**
 * Limite contratado atingido. É uma ValidationException (mensagem em 'limite') para aparecer como aviso no ecrã pelo
 * mesmo canal dos erros de validação. A mensagem é dirigida ao administrador da escola e só fala do limite contratado.
 */
class LimiteDeLicencaExcedido extends ValidationException
{
    public const CHAVE = 'limite';

    public static function para(Recurso $recurso, int $limite): self
    {
        $nome = match ($recurso) {
            Recurso::ALUNOS => 'alunos',
            Recurso::UTILIZADORES => 'utilizadores (contas que não são de aluno)',
        };

        $excepcao = static::withMessages([
            self::CHAVE => "Atingiu o limite de {$limite} {$nome} previsto na licença da escola. Para continuar, contacte a Mositec e aumente o limite contratado.",
        ]);
        $excepcao->recurso = $recurso;
        $excepcao->limite = $limite;

        return $excepcao;
    }

    public Recurso $recurso;

    public int $limite;
}
