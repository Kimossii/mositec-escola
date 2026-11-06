<?php

namespace Modules\Avaliacao\Documentos;

/**
 * O boletim de avaliação de um aluno, pronto a imprimir: cabeçalho oficial, identificação, uma linha por disciplina (ou área),
 * observações do educador, resultado e assinaturas. Sem regras de cálculo: só o que a pauta já tem.
 */
final class DocumentoBoletim
{
    /**
     * @param  list<string>  $linhasCabecalho
     * @param  list<array{0: string, 1: string}>  $identificacao  [rótulo, valor]
     * @param  list<string>  $colunas  cabeçalho da tabela (a 1.ª é a disciplina ou área)
     * @param  list<list<string>>  $linhas
     * @param  list<string>  $observacoes
     * @param  list<array{rotulo: string, nome: ?string}>  $assinaturas
     */
    public function __construct(
        public readonly string $titulo,
        public readonly array $linhasCabecalho,
        public readonly ?string $lema,
        public readonly ?string $logotipo,
        public readonly array $identificacao,
        public readonly array $colunas,
        public readonly array $linhas,
        public readonly string $resultado,
        public readonly bool $resultadoDefinitivo,
        public readonly array $observacoes,
        public readonly array $assinaturas,
        public readonly string $nota,
    ) {
    }
}
