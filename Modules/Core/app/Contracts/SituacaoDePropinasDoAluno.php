<?php

namespace Modules\Core\Contracts;

/**
 * Situação de propinas de um aluno (matrícula do ano lectivo activo), para os ecrãs do módulo Aluno
 * sem que este importe o Financeiro. Implementado pelo módulo Financeiro, que conhece Propina, Matrícula
 * e a moeda. Valores monetários em unidades menores. Respeita o tenant corrente.
 */
interface SituacaoDePropinasDoAluno
{
    /**
     * Moeda da escola, para o frontend formatar os valores.
     *
     * @return array{codigo: string, nome: string, simbolo: string, decimais: int}
     */
    public function moeda(): array;

    /**
     * Totais e últimos 6 períodos da matrícula activa do aluno.
     *
     * @return array{
     *     tem_matricula_activa: bool,
     *     matricula_id: ?int,
     *     capital_em_divida: int,
     *     multas_pendentes: int,
     *     total_em_divida: int,
     *     propinas_em_atraso: int,
     *     proximo_vencimento: ?string,
     *     estado: string,
     *     periodos: list<array{periodo: string, valor: int, pago: int, saldo: int, multa_pendente: int, estado: int, estado_descricao: string}>
     * }
     */
    public function doAluno(int $alunoId): array;

    /**
     * Só os totais e o estado por aluno, calculados numa passagem para a página inteira (sem consulta por aluno).
     *
     * @param  list<int>  $alunoIds
     * @return array<int, array{
     *     tem_matricula_activa: bool,
     *     matricula_id: ?int,
     *     capital_em_divida: int,
     *     multas_pendentes: int,
     *     total_em_divida: int,
     *     propinas_em_atraso: int,
     *     estado: string
     * }>
     */
    public function paraListaDeAlunos(array $alunoIds): array;
}
