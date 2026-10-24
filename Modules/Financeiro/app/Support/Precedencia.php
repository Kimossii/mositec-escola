<?php

namespace Modules\Financeiro\Support;

/**
 * Precedência entre alvos de planos de propina (ordem total; ganha o maior rank):
 * [turma específica ? 1 : 0, nº de dimensões definidas (curso, nível, turno),
 *  máscara de desempate curso=4 + nível=2 + turno=1]. Compara-se com `<=>`.
 *
 * Um alvo é um array com as chaves opcionais nivel_academico_id, curso_id, turno_id e
 * turma_id; nulo ou ausente = não definido. Um alvo que casa tem todas as suas dimensões
 * definidas casadas, por isso o rank depende só de quais estão definidas.
 */
final class Precedencia
{
    /**
     * @param  array<string, mixed>  $alvo
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rank(array $alvo): array
    {
        if (self::definido($alvo, 'turma_id')) {
            return [1, 0, 0];
        }

        $curso = self::definido($alvo, 'curso_id');
        $nivel = self::definido($alvo, 'nivel_academico_id');
        $turno = self::definido($alvo, 'turno_id');

        return [
            0,
            (int) $curso + (int) $nivel + (int) $turno,
            ($curso ? 4 : 0) + ($nivel ? 2 : 0) + ($turno ? 1 : 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $alvo
     */
    public static function descricao(array $alvo): string
    {
        if (self::definido($alvo, 'turma_id')) {
            return 'Turma específica';
        }

        $partes = array_filter([
            self::definido($alvo, 'curso_id') ? 'Curso' : null,
            self::definido($alvo, 'nivel_academico_id') ? 'Nível' : null,
            self::definido($alvo, 'turno_id') ? 'Turno' : null,
        ]);

        return $partes === [] ? 'Geral' : implode(' + ', $partes);
    }

    /**
     * @param  array<string, mixed>  $alvo
     */
    private static function definido(array $alvo, string $campo): bool
    {
        return ($alvo[$campo] ?? null) !== null && $alvo[$campo] !== '';
    }
}
