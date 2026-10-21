<?php

namespace Modules\Core\Actions;

use Modules\Core\Models\AuditoriaTenant;

class RegistarAuditoriaTenantAction
{
    /**
     * Regista só os campos que mudaram: `$antes` e `$depois` são comparados e só as diferenças ficam no detalhe.
     * Sem diferenças não regista nada.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $depois
     */
    public function executar(string $accao, string $entidadeTipo, ?int $entidadeId, array $antes, array $depois, ?int $utilizadorId = null): ?AuditoriaTenant
    {
        $alterados = array_keys(array_filter(
            $depois,
            fn ($valor, $campo) => ! array_key_exists($campo, $antes) || $antes[$campo] !== $valor,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($alterados === []) {
            return null;
        }

        return AuditoriaTenant::create([
            'utilizador_id' => $utilizadorId ?? auth()->id(),
            'accao' => $accao,
            'entidade_tipo' => $entidadeTipo,
            'entidade_id' => $entidadeId,
            'detalhe' => [
                'antes' => array_intersect_key($antes, array_flip($alterados)),
                'depois' => array_intersect_key($depois, array_flip($alterados)),
            ],
            'ip' => request()?->ip(),
        ]);
    }
}
