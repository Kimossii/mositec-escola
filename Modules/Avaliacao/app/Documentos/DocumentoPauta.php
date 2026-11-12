<?php

namespace Modules\Avaliacao\Documentos;

/**
 * Uma pauta pronta a imprimir ou exportar, já sem regras de cálculo: cabeçalho oficial, metadados, colunas (fixas, grupos
 * com subcolunas e finais) e linhas de texto. Os escritores de PDF e de Excel só desenham isto.
 */
final class DocumentoPauta
{
    /**
     * @param  list<string>  $linhasCabecalho  República, Ministério… até ao nome da escola
     * @param  list<array{0: string, 1: string}>  $meta  [rótulo, valor]: Classe, Turma, Ano lectivo…
     * @param  list<string>  $fixas  colunas à esquerda (N.º, Nome…)
     * @param  list<array{rotulo: string, colunas: list<string>}>  $grupos  uma por disciplina ou trimestre
     * @param  list<string>  $finais  colunas à direita (Resultado final, Obs.)
     * @param  list<list<string>>  $linhas  cada linha tem uma célula por coluna, pela ordem fixas, grupos, finais
     * @param  list<array{rotulo: string, nome: ?string, cargo: ?string}>  $assinaturas
     */
    public function __construct(
        public readonly string $titulo,
        public readonly array $linhasCabecalho,
        public readonly ?string $lema,
        public readonly ?string $logotipo,
        public readonly array $meta,
        public readonly array $fixas,
        public readonly array $grupos,
        public readonly array $finais,
        public readonly array $linhas,
        public readonly array $assinaturas,
        public readonly ?string $nota = null,
    ) {
    }

    /** Posição (a partir de 0) da coluna do nome do aluno, para a alinhar à esquerda. */
    public function indiceDoNome(): ?int
    {
        foreach (['Nome do aluno', 'Nome da criança', 'Nome'] as $rotulo) {
            $i = array_search($rotulo, $this->fixas, true);
            if ($i !== false) {
                return $i;
            }
        }

        return null;
    }

    public function totalDeColunas(): int
    {
        return count($this->fixas) + array_sum(array_map(fn (array $g) => count($g['colunas']), $this->grupos)) + count($this->finais);
    }
}
