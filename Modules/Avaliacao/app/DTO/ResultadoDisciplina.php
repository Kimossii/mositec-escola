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
    ) {
    }

    /** A nota que vale no fim: a MF depois do exame, ou a MFD nas disciplinas sem exame. */
    public function notaFinalArredondada(): ?string
    {
        return $this->temExame ? $this->mfArredondada : $this->mfdArredondada;
    }

    public function temPendentes(): bool
    {
        return in_array(true, $this->pendentes, true);
    }
}
