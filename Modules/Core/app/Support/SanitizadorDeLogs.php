<?php

namespace Modules\Core\Support;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use UnitEnum;

/**
 * Prepara o contexto de um log para ser escrito com segurança: esconde segredos (senhas, tokens, cookies, IBAN…),
 * limita tamanho e profundidade (um log nunca deve pesar nem derrubar o pedido) e reduz objetos a uma descrição curta.
 */
class SanitizadorDeLogs
{
    public const PROFUNDIDADE_MAXIMA = 4;

    public const ITENS_MAXIMOS = 50;

    public const TEXTO_MAXIMO = 500;

    private const CHAVE_SENSIVEL = '/pass|senha|secret|token|authorization|cookie|api[_-]?key|cvv|\bpin\b|iban|otp|credencial|credit/i';

    /** @param  array<mixed>  $contexto */
    public static function contexto(array $contexto, int $nivel = 0): array
    {
        $limpo = [];
        $contador = 0;

        foreach ($contexto as $chave => $valor) {
            if (++$contador > self::ITENS_MAXIMOS) {
                $limpo['…'] = (count($contexto) - self::ITENS_MAXIMOS).' item(ns) omitido(s)';
                break;
            }

            $limpo[$chave] = is_string($chave) && preg_match(self::CHAVE_SENSIVEL, $chave) === 1
                ? '[ESCONDIDO]'
                : self::valor($valor, $nivel);
        }

        return $limpo;
    }

    /** Uma linha, sem quebras nem caracteres de controlo (evita forjar entradas no ficheiro de log), e com tamanho limitado. */
    public static function texto(string $texto, int $maximo = self::TEXTO_MAXIMO): string
    {
        $texto = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texto) ?? '';

        return mb_strlen($texto) > $maximo ? mb_substr($texto, 0, $maximo).'…' : $texto;
    }

    private static function valor(mixed $valor, int $nivel): mixed
    {
        return match (true) {
            $valor === null, is_bool($valor), is_int($valor), is_float($valor) => $valor,
            is_string($valor) => self::texto($valor),
            is_array($valor) => $nivel >= self::PROFUNDIDADE_MAXIMA ? '[array]' : self::contexto($valor, $nivel + 1),
            $valor instanceof Throwable => ['excecao' => $valor::class, 'mensagem' => self::texto($valor->getMessage()), 'em' => basename($valor->getFile()).':'.$valor->getLine()],
            $valor instanceof Model => $valor::class.'#'.($valor->getKey() ?? 'novo'),
            $valor instanceof UnitEnum => $valor::class.'::'.$valor->name,
            $valor instanceof \Stringable => self::texto((string) $valor),
            is_object($valor) => $valor::class,
            default => gettype($valor),
        };
    }
}
