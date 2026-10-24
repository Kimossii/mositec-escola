<?php

namespace Modules\Core\Contracts;

/**
 * Só leitura: o que seria cancelado se a matrícula terminasse na data indicada (ex.: propinas futuras).
 * Implementações etiquetadas com ETIQUETA; sem implementações a lista é vazia.
 */
interface PrevisaoCancelamentoAoTerminarMatricula
{
    public const ETIQUETA = 'matricula.previsao-cancelamento';

    /**
     * @return list<array{descricao: string, vencida: bool}>
     */
    public function prever(int $matriculaId, string $dataEfeito): array;
}
