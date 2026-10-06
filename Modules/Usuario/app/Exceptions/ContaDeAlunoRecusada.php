<?php

namespace Modules\Usuario\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * Regra de domínio: só se cria conta para um aluno activo, da própria escola e sem conta. Sai como
 * erro de validação do campo `numero_matricula`. "Não existe" e "é de outra escola" têm a MESMA
 * mensagem, para não revelar que a matrícula existe noutra escola.
 */
class ContaDeAlunoRecusada extends ValidationException
{
    public static function naoEncontrado(): static
    {
        return static::withMessages(['numero_matricula' => 'Não foi encontrado nenhum aluno com este número de matrícula.']);
    }

    public static function inativo(): static
    {
        return static::withMessages(['numero_matricula' => 'Este aluno está inativo. Só é possível criar conta para alunos ativos.']);
    }

    public static function jaTemConta(): static
    {
        return static::withMessages(['numero_matricula' => 'Este aluno já tem conta.']);
    }
}
