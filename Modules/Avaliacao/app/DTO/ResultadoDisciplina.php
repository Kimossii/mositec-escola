<?php

namespace Modules\Avaliacao\DTO;

/**
 * Resultado quantitativo de um aluno numa disciplina. Os arrays são indexados pelo trimestre (1 a 3) e os valores são
 * decimais em `string` com precisão total (`null` = ainda não existe). `mac` pode ser parcial enquanto o trimestre está
 * pendente; `mt` só existe com o trimestre completo; `mfd` só com os três `mt`.
 */
readonly class ResultadoDisciplina
{
    /**
     * @param  array<int, ?string>  $mac
     * @param  array<int, ?string>  $pt
     * @param  array<int, ?string>  $mt
     * @param  array<int, bool>  $pendentes
     */
    public function __construct(
        public array $mac,
        public array $pt,
        public array $mt,
        public array $pendentes,
        public ?string $mfd,
        public ?string $mfdArredondada,
        public ?string $ne = null,
        public ?string $mf = null,
        public ?string $mfArredondada = null,
        public bool $temExame = false,
        public ?string $ner = null,
        public ?string $mfr = null,
        public ?string $mfrArredondada = null,
        public bool $recursoNecessario = false,
    ) {
    }

    /** A nota antes do recurso: a MF depois do exame, ou a MFD nas disciplinas sem exame próprio. */
    public function baseArredondada(): ?string
    {
        return $this->temExame ? $this->mfArredondada : $this->mfdArredondada;
    }

    /**
     * A nota que vale no fim: a MFR se houve recurso (decide mesmo que seja mais baixa que a MF); senão a nota base. O recurso
     * não é obrigatório: sem NER, a disciplina fica com a nota que tinha.
     */
    public function notaFinalArredondada(): ?string
    {
        return $this->mfrArredondada ?? $this->baseArredondada();
    }

    public function temPendentes(): bool
    {
        return in_array(true, $this->pendentes, true);
    }
}
