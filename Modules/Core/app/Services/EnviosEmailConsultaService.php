<?php

namespace Modules\Core\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Models\EnvioEmail;

/** Leitura do registo de envios de email da escola (`envios_email`), isolado por tenant pelo próprio Model. Só leitura. */
class EnviosEmailConsultaService
{
    /** @param array{estado?: ?string, categoria?: ?string, de?: ?string, ate?: ?string} $filtros */
    public function listar(array $filtros, int $porPagina = 25): LengthAwarePaginator
    {
        return EnvioEmail::query()
            ->when(isset($filtros['estado']), fn ($q) => $q->where('estado', (int) $filtros['estado']))
            ->when($filtros['categoria'] ?? null, fn ($q, $v) => $q->where('categoria', $v))
            ->when($filtros['de'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filtros['ate'] ?? null, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('created_at')
            ->latest('id')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (EnvioEmail $e) => [
                'id' => $e->id,
                'quando' => $e->created_at?->format('Y-m-d H:i:s'),
                'notificacao' => $e->notificacao,
                'categoria' => $e->categoria->value,
                'estado' => $e->estado->value,
                'estado_descricao' => $e->estado_descricao,
                'canal_descricao' => $e->canal_descricao,
                'destinatario' => $e->destinatario_mascarado,
                'erro' => $e->erro,
            ]);
    }

    /**
     * Totais dos últimos dias por estado, para o aviso do topo da página.
     *
     * @return array{dias: int, enviados: int, falhas: int, descartados: int}
     */
    public function resumo(int $dias = 7): array
    {
        $porEstado = EnvioEmail::query()
            ->where('created_at', '>=', now()->subDays($dias))
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        return [
            'dias' => $dias,
            'enviados' => (int) ($porEstado[EstadoEnvioEmail::ENVIADO->value] ?? 0),
            'falhas' => (int) ($porEstado[EstadoEnvioEmail::FALHOU->value] ?? 0),
            'descartados' => (int) ($porEstado[EstadoEnvioEmail::DESCARTADO_LIMITE->value] ?? 0) + (int) ($porEstado[EstadoEnvioEmail::DESCARTADO_CONTRATO->value] ?? 0),
        ];
    }
}
