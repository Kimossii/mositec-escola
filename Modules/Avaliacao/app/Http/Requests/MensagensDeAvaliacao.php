<?php

namespace Modules\Avaliacao\Http\Requests;

/**
 * As mensagens de validação em português de todos os pedidos da avaliação. Cada campo tem um rótulo (com o género, para
 * concordar «obrigatório/obrigatória») e as mensagens geram-se por regra, em vez de se repetirem em cada pedido.
 */
final class MensagensDeAvaliacao
{
    /** campo => [rótulo, género: 'o' ou 'a'] */
    private const CAMPOS = [
        'turma' => ['A turma', 'a'], 'turma_id' => ['A turma', 'a'], 'disciplina' => ['A disciplina', 'a'], 'disciplina_id' => ['A disciplina', 'a'],
        'periodo' => ['O trimestre', 'o'], 'periodo_id' => ['O trimestre', 'o'], 'ano' => ['O ano lectivo', 'o'], 'ano_lectivo_id' => ['O ano lectivo', 'o'],
        'nivel_academico_id' => ['A classe', 'a'], 'instrumento_id' => ['A avaliação', 'a'], 'inscricao_disciplina_id' => ['A inscrição do aluno', 'a'],
        'matricula' => ['A matrícula', 'a'], 'matricula_id' => ['A matrícula', 'a'], 'competencia_id' => ['A competência', 'a'], 'escala_id' => ['A escala', 'a'],
        'escala_nivel_id' => ['O nível', 'o'], 'regra_id' => ['A regra', 'a'], 'valor' => ['A nota', 'a'], 'motivo' => ['O motivo', 'o'],
        'observacao' => ['A observação', 'a'], 'conta_como_zero' => ['A opção «conta como zero»', 'a'], 'nome' => ['O nome', 'o'], 'tipo' => ['O tipo', 'o'],
        'obrigatorio' => ['A obrigatoriedade', 'a'], 'formato' => ['O formato', 'o'], 'detalhe' => ['O detalhe', 'o'], 'decisao' => ['A decisão', 'a'],
        'progressao' => ['A progressão', 'a'], 'data_limite' => ['A data limite', 'a'], 'escala_maxima' => ['A escala máxima', 'a'], 'nota_minima' => ['A nota mínima', 'a'],
        'peso_mac' => ['O peso da avaliação contínua', 'o'], 'peso_pt' => ['O peso da prova trimestral', 'o'], 'peso_mt1' => ['O peso do 1.º trimestre', 'o'],
        'peso_mt2' => ['O peso do 2.º trimestre', 'o'], 'peso_mt3' => ['O peso do 3.º trimestre', 'o'], 'peso_mfd_exame' => ['O peso da média final no exame', 'o'],
        'peso_exame' => ['O peso do exame', 'o'], 'casas_decimais' => ['O número de casas decimais', 'o'], 'modo_arredondamento' => ['O arredondamento', 'o'],
        'reprova_por_aproveitamento' => ['A reprovação por aproveitamento', 'a'], 'recurso_max_disciplinas' => ['O máximo de disciplinas negativas', 'o'],
        'disciplinas_chave' => ['As disciplinas-chave', 'a'], 'disciplinas_chave.*' => ['A disciplina-chave', 'a'], 'linhas_extra' => ['As linhas do cabeçalho', 'a'],
        'lema' => ['O lema', 'o'], 'visto_cargo' => ['O cargo do visto', 'o'], 'visto_nome' => ['O nome do visto', 'o'], 'competencias' => ['As competências', 'a'],
        'competencias.*.id' => ['A competência', 'a'], 'competencias.*.descricao' => ['A descrição da competência', 'a'], 'competencias.*.ativa' => ['A opção «activa»', 'a'],
        'id' => ['A escala', 'a'], 'niveis' => ['Os níveis da escala', 'o'], 'niveis.*.id' => ['O nível', 'o'], 'niveis.*.codigo' => ['O código do nível', 'o'],
        'niveis.*.designacao' => ['A designação do nível', 'a'],
    ];

    /** @return array<string, string> */
    public static function todas(): array
    {
        $mensagens = [];

        foreach (self::CAMPOS as $campo => [$rotulo, $genero]) {
            $obrigatorio = $genero === 'a' ? 'obrigatória' : 'obrigatório';
            $invalido = $genero === 'a' ? 'inválida' : 'inválido';

            $mensagens += [
                "{$campo}.required" => "{$rotulo} é {$obrigatorio}.",
                "{$campo}.present" => "{$rotulo} é {$obrigatorio}.",
                "{$campo}.integer" => "{$rotulo} é {$invalido}.",
                "{$campo}.string" => "{$rotulo} é {$invalido}.",
                "{$campo}.boolean" => "{$rotulo} é {$invalido}.",
                "{$campo}.array" => "{$rotulo} é {$invalido}.",
                "{$campo}.in" => "{$rotulo} é {$invalido}.",
                "{$campo}.numeric" => "{$rotulo} tem de ser um número.",
                "{$campo}.date_format" => "{$rotulo} tem de estar no formato AAAA-MM-DD.",
                "{$campo}.max" => "{$rotulo} excede o limite permitido.",
                "{$campo}.min" => "{$rotulo} não atinge o mínimo permitido.",
                "{$campo}.between" => "{$rotulo} está fora dos limites permitidos.",
            ];
        }

        return $mensagens;
    }
}
