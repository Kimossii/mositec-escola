<?php

namespace Modules\Usuario\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Regra de domínio: só se cria conta para um docente activo, da própria escola e sem conta, com um email livre. Sai como
 * erro de validação (`docente_id`, ou `email` para o email em uso). "Não existe" e "é de outra escola" têm a MESMA
 * mensagem, para não revelar que o docente existe noutra escola.
 */
class ContaDeDocenteRecusada extends ValidationException
{
    public static function naoEncontrado(): static
    {
        return static::withMessages(['docente_id' => 'Não foi encontrado o docente indicado.']);
    }

    public static function inativo(): static
    {
        return static::withMessages(['docente_id' => 'Este docente está inativo. Só é possível criar acesso para docentes ativos.']);
    }

    public static function jaTemConta(): static
    {
        return static::withMessages(['docente_id' => 'Este docente já tem acesso.']);
    }

    public static function emailEmUso(): static
    {
        return static::withMessages(['email' => 'Este email já está em uso.']);
    }
}
