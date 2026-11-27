<?php

namespace Modules\Plataforma\Services;

use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Models\EnvioEmail;
use Modules\Tenant\Models\Tenant;

/**
 * Falhas de envio de email de todas as escolas, para o painel. Lê `envios_email` sem contexto de escola (por isso sem o
 * escopo de tenant): é a única forma de o Super Admin ver o que falhou, e só devolve metadados (nunca o endereço completo).
 */
class EnviosEmailConsultaService
{
    /**
     * As falhas mais recentes, da mais nova para a mais antiga, com a escola já resolvida.
     *
     * @return list<array{id: int, escola: ?string, escola_nome: ?string, notificacao: string, canal: string, erro: ?string, created_at: ?string}>
     */
    public function falhasRecentes(int $limite = 10): array
    {
        $falhas = EnvioEmail::query()
            ->withoutGlobalScopes()
            ->where('estado', EstadoEnvioEmail::FALHOU->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        $escolas = Tenant::query()->whereIn('id', $falhas->pluck('tenant_id')->unique())->get(['id', 'codigo', 'nome'])->keyBy('id');

        return $falhas->map(fn (EnvioEmail $f) => [
            'id' => $f->id,
            'escola' => $escolas[$f->tenant_id]->codigo ?? null,
            'escola_nome' => $escolas[$f->tenant_id]->nome ?? null,
            'notificacao' => $f->notificacao,
            'canal' => $f->canal_descricao,
            'erro' => $f->erro,
            'created_at' => $f->created_at?->toIso8601String(),
        ])->values()->all();
    }

    /** Quantas falhas houve em toda a Plataforma nos últimos dias. */
    public function totalDeFalhas(int $dias = 7): int
    {
        return EnvioEmail::query()
            ->withoutGlobalScopes()
            ->where('estado', EstadoEnvioEmail::FALHOU->value)
            ->where('created_at', '>=', now()->subDays($dias))
            ->count();
    }
}
