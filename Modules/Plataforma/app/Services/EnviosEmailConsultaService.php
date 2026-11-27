<?php

namespace Modules\Plataforma\Services;

use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Models\EnvioEmail;
use Modules\Core\Tenancy\ConsultaEntreEscolas;
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
        $falhas = ConsultaEntreEscolas::de(EnvioEmail::class)
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
        return ConsultaEntreEscolas::de(EnvioEmail::class)
            ->where('estado', EstadoEnvioEmail::FALHOU->value)
            ->where('created_at', '>=', now()->subDays($dias))
            ->count();
    }

    /**
     * Escolas que configuraram servidor próprio, com o estado. NUNCA devolve servidor, utilizador, senha nem remetente: a
     * Plataforma só precisa de saber quem está a enviar por conta própria e se está bem.
     *
     * @return list<array{escola: ?string, escola_nome: ?string, estado: int, estado_descricao: string, activo: bool, ultimo_erro: ?string}>
     */
    public function infraPorEscola(): array
    {
        $configuracoes = ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->orderByDesc('updated_at')->get(['tenant_id', 'estado', 'estado_descricao', 'activo', 'bloqueada_pela_plataforma', 'ultimo_erro']);
        $escolas = Tenant::query()->whereIn('id', $configuracoes->pluck('tenant_id'))->get(['id', 'codigo', 'nome'])->keyBy('id');

        return $configuracoes->map(fn (ConfiguracaoEmail $c) => [
            'escola' => $escolas[$c->tenant_id]->codigo ?? null,
            'escola_nome' => $escolas[$c->tenant_id]->nome ?? null,
            'estado' => $c->estado->value,
            'estado_descricao' => $c->estado_descricao,
            'activo' => (bool) $c->activo,
            'bloqueada' => (bool) $c->bloqueada_pela_plataforma,
            'ultimo_erro' => $c->ultimo_erro,
        ])->values()->all();
    }

    /**
     * Estado do servidor próprio da escola para o botão da Plataforma: a enviar agora? bloqueada?
     *
     * @return array{activo: bool, bloqueada: bool}
     */
    public function estadoDoServidorProprio(Tenant $tenant): array
    {
        $c = ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->where('tenant_id', $tenant->id)->first(['activo', 'bloqueada_pela_plataforma']);

        return ['activo' => (bool) $c?->activo, 'bloqueada' => (bool) $c?->bloqueada_pela_plataforma];
    }
}
