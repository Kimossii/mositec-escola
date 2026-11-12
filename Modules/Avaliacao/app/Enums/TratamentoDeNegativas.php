<?php

namespace Modules\Avaliacao\Enums;

/**
 * O que acontece a um aluno com negativa na pauta final (regra da escola, por classe).
 * - Reprova: a negativa decide (com recurso, nas classes que o têm).
 * - Transita com as negativas tratadas: o aluno tem de transitar, mas cada negativa tem de ter o seu tratamento
 *   registado antes de a pauta ser aprovada (por exemplo, 1.ª e 2.ª classes).
 */
enum TratamentoDeNegativas: int
{
    case REPROVA = 0;
    case TRANSITA_TRATANDO_NEGATIVAS = 1;

    public function label(): string
    {
        return match ($this) {
            self::REPROVA => 'Reprova se tiver negativa',
            self::TRANSITA_TRATANDO_NEGATIVAS => 'Transita, com as negativas tratadas',
        };
    }

    /** Frase curta para o selo da negativa na mini-pauta. */
    public function consequencia(): string
    {
        return match ($this) {
            self::REPROVA => 'Em risco de reprovar',
            self::TRANSITA_TRATANDO_NEGATIVAS => 'Tem de ser tratada para a pauta ser aprovada',
        };
    }

    /** Em que classes o aluno tem de transitar: a negativa não reprova, trata-se. */
    public function tratamNegativas(): bool
    {
        return $this === self::TRANSITA_TRATANDO_NEGATIVAS;
    }
}
