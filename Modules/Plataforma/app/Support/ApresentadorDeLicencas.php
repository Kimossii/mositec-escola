<?php

namespace Modules\Plataforma\Support;

use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Services\LicencasConsultaService;

/** Forma única com que uma licença emitida chega às páginas do painel. Nunca leva o token (só o detalhe, explicitamente). */
class ApresentadorDeLicencas
{
    public function __construct(private readonly LicencasConsultaService $consulta) {}

    /**
     * @param  array<int, string>  $nomesDosPlanos
     * @return array<string, mixed>
     */
    public function resumo(LicencaEmitida $licenca, array $nomesDosPlanos = []): array
    {
        return [
            'id' => $licenca->id,
            'plano_id' => $licenca->plano_id,
            'plano' => $nomesDosPlanos[$licenca->plano_id] ?? null,
            'escola' => $licenca->escola,
            'instalacao' => $licenca->instalacao,
            'modalidade' => $licenca->modalidade->value,
            'modalidade_descricao' => $licenca->modalidade_descricao,
            'estado_comercial' => $licenca->estado_comercial->value,
            'estado_comercial_descricao' => $licenca->estado_comercial_descricao,
            'validade' => $this->consulta->validade($licenca),
            'ligacao_obrigatoria' => $licenca->ligacao_obrigatoria,
            'emitida_em' => $licenca->emitida_em,
            'inicio' => $licenca->inicio,
            'expira_em' => $licenca->expira_em,
            'tolerancia_dias' => $licenca->tolerancia_dias,
            'aviso_dias' => $licenca->aviso_dias,
            'limites' => [
                'alunos' => $licenca->limite_alunos,
                'utilizadores' => $licenca->limite_utilizadores,
                'estabelecimentos' => $licenca->limite_estabelecimentos,
            ],
            'modulos' => $licenca->modulos,
        ];
    }
}
