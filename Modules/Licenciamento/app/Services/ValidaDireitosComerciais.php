<?php

namespace Modules\Licenciamento\Services;

use Modules\Licenciamento\Documento\DireitosDaLicenca;

/** Regras comuns a planos e licenças: limites, tolerância/aviso e módulos (validados contra a configuração, que é extensível). */
class ValidaDireitosComerciais
{
    public const CHAVES_LIMITES = ['alunos', 'utilizadores'];

    /**
     * @param  array<string,?int>  $limites
     * @param  list<string>|null  $modulos  null = não validar (sem sobreposição)
     * @return array<string,string> erros por campo
     */
    public function erros(array $limites, ?int $tolerancia, ?int $aviso, ?array $modulos, ?int $validadeDias = null): array
    {
        $erros = [];

        foreach ($limites as $chave => $valor) {
            if (! in_array($chave, self::CHAVES_LIMITES, true)) {
                $erros['limites'] = "Limite desconhecido: {$chave}.";
            } elseif ($valor !== null && ($valor < 0 || $valor > DireitosDaLicenca::MAX_LIMITE)) {
                $erros['limites'] = 'Os limites não podem ser negativos nem exceder ' . DireitosDaLicenca::MAX_LIMITE . '.';
            }
        }

        if ($tolerancia !== null && ($tolerancia < 0 || $tolerancia > DireitosDaLicenca::MAX_DIAS)) {
            $erros['tolerancia_dias'] = 'A tolerância não pode ser negativa.';
        }

        if ($aviso !== null && ($aviso < 0 || $aviso > DireitosDaLicenca::MAX_DIAS)) {
            $erros['aviso_dias'] = 'Os dias de aviso não podem ser negativos.';
        }

        if ($modulos !== null) {
            $permitidos = (array) config('licenciamento.modulos_licenciaveis', []);

            if ($modulos === []) {
                $erros['modulos'] = 'Escolha pelo menos um módulo.';
            } elseif (array_diff($modulos, $permitidos) !== []) {
                $erros['modulos'] = 'Módulo desconhecido: ' . implode(', ', array_diff($modulos, $permitidos)) . '.';
            }
        }

        if ($validadeDias !== null && ($validadeDias < 1 || $validadeDias > DireitosDaLicenca::MAX_DIAS)) {
            $erros['validade_dias'] = 'A validade tem de ser de 1 a ' . DireitosDaLicenca::MAX_DIAS . ' dias.';
        }

        return $erros;
    }
}
