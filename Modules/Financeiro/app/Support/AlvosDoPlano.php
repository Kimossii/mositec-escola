<?php

namespace Modules\Financeiro\Support;

/**
 * Normalização dos alvos de um plano (nivel_academico_id, curso_id, turno_id, turma_id).
 * Linhas totalmente vazias são ignoradas; sem nenhum alvo o plano aplica-se a tudo,
 * representado por um alvo vazio ao comparar planos.
 */
final class AlvosDoPlano
{
    private const CAMPOS = ['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'];

    /**
     * Alvos únicos para gravar (exclui os vazios).
     *
     * @param  array<int, mixed>  $alvos
     * @return list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>
     */
    public static function paraGravar(array $alvos): array
    {
        $unicos = [];

        foreach ($alvos as $alvo) {
            $normalizado = self::alvo($alvo);

            if (self::vazio($normalizado)) {
                continue;
            }

            $unicos[self::chave($normalizado)] = $normalizado;
        }

        return array_values($unicos);
    }

    /**
     * Como paraGravar, mas sem alvos devolve um alvo vazio: serve para comparar planos.
     *
     * @param  array<int, mixed>  $alvos
     * @return list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>
     */
    public static function normalizar(array $alvos): array
    {
        $alvosParaGravar = self::paraGravar($alvos);

        return $alvosParaGravar === [] ? [self::alvo([])] : $alvosParaGravar;
    }

    /**
     * @param  array<string, mixed>  $alvo
     */
    public static function chave(array $alvo): string
    {
        $normalizado = self::alvo($alvo);

        return implode('|', array_map(fn (string $campo) => (string) $normalizado[$campo], self::CAMPOS));
    }

    /**
     * @param  array<int, mixed>  $alvos
     */
    public static function temRepetidos(array $alvos): bool
    {
        $chaves = [];

        foreach ($alvos as $alvo) {
            $normalizado = self::alvo($alvo);

            if (self::vazio($normalizado)) {
                continue;
            }

            $chave = self::chave($normalizado);

            if (isset($chaves[$chave])) {
                return true;
            }

            $chaves[$chave] = true;
        }

        return false;
    }

    /**
     * Um alvo com turma definida não pode ter nível, curso nem turno.
     *
     * @param  array<int, mixed>  $alvos
     */
    public static function violaExclusividadeDaTurma(array $alvos): bool
    {
        foreach ($alvos as $alvo) {
            $normalizado = self::alvo($alvo);

            if ($normalizado['turma_id'] !== null
                && ($normalizado['nivel_academico_id'] !== null || $normalizado['curso_id'] !== null || $normalizado['turno_id'] !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $alvos
     * @return list<int>
     */
    public static function idsDeTurma(array $alvos): array
    {
        $ids = [];

        foreach ($alvos as $alvo) {
            $turma = self::alvo($alvo)['turma_id'];

            if ($turma !== null) {
                $ids[$turma] = $turma;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}
     */
    private static function alvo(mixed $alvo): array
    {
        $alvo = is_array($alvo) ? $alvo : [];
        $resultado = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $alvo[$campo] ?? null;
            $resultado[$campo] = $valor === null || $valor === '' ? null : (int) $valor;
        }

        return $resultado;
    }

    /**
     * @param  array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}  $alvo
     */
    private static function vazio(array $alvo): bool
    {
        return $alvo['nivel_academico_id'] === null && $alvo['curso_id'] === null
            && $alvo['turno_id'] === null && $alvo['turma_id'] === null;
    }
}
