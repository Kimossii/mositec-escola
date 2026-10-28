<?php

namespace Modules\Core\Exportacao;

/**
 * Uma tabela pronta a exportar (Excel, PDF, CSV ou ZIP): título, colunas (`chave => rótulo`) e linhas (arrays indexados
 * pelas chaves das colunas, com valores escalares ou nulos). Quem exporta só descreve a tabela; o `Exportador` trata dos formatos.
 */
final class TabelaExportavel
{
    /**
     * @param  array<string, string>  $colunas  chave => rótulo
     * @param  iterable<array<string, scalar|null>>  $linhas
     * @param  list<string>  $assinaturas  espaços de assinatura no fim do PDF (rótulo por baixo de cada linha); `[]` para nenhum
     */
    public function __construct(
        public readonly string $titulo,
        public readonly array $colunas,
        public readonly iterable $linhas,
        public readonly ?string $subtitulo = null,
        public readonly ?string $entidade = null,
        public readonly array $assinaturas = ['Elaborado por', 'Responsável'],
    ) {
    }

    /** @return list<array<string, scalar|null>> */
    public function linhasComoLista(): array
    {
        return is_array($this->linhas) ? array_values($this->linhas) : iterator_to_array($this->linhas, false);
    }
}
