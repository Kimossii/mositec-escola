<?php

namespace Modules\Avaliacao\Services;

/**
 * As regras por defeito de uma escola nova, agrupadas como a escola as vive: cada regra serve várias classes e as
 * classes ligam-se a ela pelo código. Servem só para arrancar; depois a escola muda o que quiser na configuração.
 */
class RegraPadraoService
{
    /**
     * @return list<array{nome: string, codigos: list<string>, qualitativa: bool, escala_maxima: int, nota_minima: string, reprova_por_aproveitamento: bool, com_exame: bool, com_recurso: bool}>
     */
    public function regras(): array
    {
        $numerica = fn (string $nome, array $codigos, int $escala, string $minima, bool $reprova = true, bool $exame = false, bool $recurso = false) => [
            'nome' => $nome, 'codigos' => $codigos, 'qualitativa' => false, 'escala_maxima' => $escala, 'nota_minima' => $minima,
            'reprova_por_aproveitamento' => $reprova, 'com_exame' => $exame, 'com_recurso' => $recurso,
        ];

        return [
            ['nome' => 'Creche e Pré-Escolar', 'codigos' => ['CRECHE_I', 'CRECHE_II', 'PRE_I', 'PRE_II', 'PRE_III'], 'qualitativa' => true,
                'escala_maxima' => 0, 'nota_minima' => '0', 'reprova_por_aproveitamento' => false, 'com_exame' => false, 'com_recurso' => false],
            $numerica('1.ª e 2.ª classe', ['PRI_1', 'PRI_2'], 10, '5', reprova: false),
            $numerica('3.ª a 5.ª classe', ['PRI_3', 'PRI_4', 'PRI_5'], 10, '5'),
            $numerica('6.ª classe', ['PRI_6'], 10, '5', exame: true, recurso: true),
            $numerica('7.ª e 8.ª classe', ['SEC_7', 'SEC_8'], 20, '10', recurso: true),
            $numerica('9.ª classe', ['SEC_9'], 20, '10', exame: true, recurso: true),
        ];
    }

    /** Com exame próprio, por defeito, conforme o código da classe (só quando a regra não o diz). */
    public function temExameProprio(string $codigoNivel): bool
    {
        return $this->regraDe($codigoNivel)['com_exame'] ?? false;
    }

    /** Com exame de recurso, por defeito, conforme o código da classe (só quando a regra não o diz). */
    public function temRecurso(string $codigoNivel): bool
    {
        return $this->regraDe($codigoNivel)['com_recurso'] ?? false;
    }

    /** A regra por defeito da classe, ou `null` se a classe não tem (10.ª em diante, ou códigos próprios da escola). */
    public function regraDe(string $codigoNivel): ?array
    {
        foreach ($this->regras() as $regra) {
            if (in_array($codigoNivel, $regra['codigos'], true)) {
                return $regra;
            }
        }

        return null;
    }
}
