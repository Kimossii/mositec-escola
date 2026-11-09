<?php

namespace Modules\Core\Actions;

use Modules\Core\DTO\AuditoriaTenantDTO;
use Modules\Core\Models\AuditoriaTenant;
use Modules\Core\Traits\LogService;

class RegistarAuditoriaTenantAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(AuditoriaTenantDTO $dto): ?AuditoriaTenant
    {
        return $this->comLog('Registar auditoria escola', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    /**
     * Regista só os campos que mudaram: `$antes` e `$depois` são comparados e só as diferenças ficam no detalhe.
     * Sem diferenças não regista nada.
     *
     */
    private function executarSemRegisto(AuditoriaTenantDTO $dto): ?AuditoriaTenant
    {
        [$antes, $depois] = [$dto->antes, $dto->depois];
        $alterados = array_keys(array_filter(
            $depois,
            fn ($valor, $campo) => ! array_key_exists($campo, $antes) || $antes[$campo] !== $valor,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($alterados === []) {
            return null;
        }

        return AuditoriaTenant::create([
            'utilizador_id' => $dto->utilizadorId ?? auth()->id(),
            'accao' => $dto->accao,
            'entidade_tipo' => $dto->entidadeTipo,
            'entidade_id' => $dto->entidadeId,
            'detalhe' => [
                'antes' => array_intersect_key($antes, array_flip($alterados)),
                'depois' => array_intersect_key($depois, array_flip($alterados)),
            ],
            'ip' => request()?->ip(),
        ]);
    }
}
