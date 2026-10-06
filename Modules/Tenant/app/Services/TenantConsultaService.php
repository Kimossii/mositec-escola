<?php

namespace Modules\Tenant\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\TenantNaoEncontrado;
use Modules\Tenant\Models\Tenant;

/**
 * Leitura de gestão dos tenants. Só toca em `tenants` e `domains` (tabelas globais): nunca
 * em dados de escola, para poder correr sem contexto de tenant (painel da Plataforma).
 */
class TenantConsultaService
{
    /**
     * @throws TenantNaoEncontrado
     */
    public function porCodigo(string $codigo): Tenant
    {
        return Tenant::query()->where('codigo', trim($codigo))->first()
            ?? throw new TenantNaoEncontrado(trim($codigo));
    }

    /**
     * Listagem paginada, a mais recente primeiro, com o domínio principal já carregado (sem N+1).
     * `$estado` é o valor numérico de EstadoTenant (desconhecido ou vazio = sem filtro); `$pesquisa`
     * procura, sem distinguir maiúsculas, no nome, no código e em qualquer domínio da escola.
     *
     * @return LengthAwarePaginator<int, Tenant>
     */
    public function listar(?string $estado = null, ?string $pesquisa = null, int $porPagina = 15): LengthAwarePaginator
    {
        $estadoFiltro = is_numeric($estado) ? EstadoTenant::tryFrom((int) $estado) : null;
        $termo = trim((string) $pesquisa);

        return Tenant::query()
            ->with('dominioPrincipal')
            ->when($estadoFiltro !== null, fn (Builder $q) => $q->where('estado', $estadoFiltro->value))
            ->when($termo !== '', function (Builder $q) use ($termo) {
                // Pesquisa partilhada (ignora maiusculas e trata `%`, `_` como texto). `exists` em vez de
                // join: uma escola com dois dominios a coincidir nao duplica a linha.
                $q->where(fn (Builder $q) => $q
                    ->whereContem('nome', $termo)
                    ->orWhereContem('codigo', $termo)
                    ->orWhereHas('dominios', fn (Builder $d) => $d->whereContem('dominio', $termo)));
            })
            ->orderByDesc('id')
            ->paginate($porPagina)
            ->withQueryString();
    }

    /**
     * O tenant com todos os domínios, o principal primeiro e os restantes por ordem alfabética.
     */
    public function detalhe(Tenant $tenant): Tenant
    {
        return $tenant->load(['dominios' => fn ($q) => $q->orderByDesc('is_principal')->orderBy('dominio')]);
    }

    /**
     * O que o estado da escola permite fazer, para a interface só oferecer o que as Actions aceitam
     * (a autoridade continua a ser a Action: um teste confronta os dois). Encerrada é terminal.
     *
     * @return array{suspender: bool, reactivar: bool, encerrar: bool, gerir_dominios: bool, recuperar_administrador: bool, revogar_acessos: bool}
     */
    public function accoesPermitidas(Tenant $tenant): array
    {
        $estado = $tenant->estado;

        return [
            'suspender' => $estado === EstadoTenant::ACTIVO,
            'reactivar' => $estado === EstadoTenant::SUSPENSO,
            'encerrar' => $estado !== EstadoTenant::ENCERRADO,
            'gerir_dominios' => $estado !== EstadoTenant::ENCERRADO,
            // Mesma regra do contrato RecuperaAdministradorDoTenant: só escolas Activas.
            'recuperar_administrador' => $estado === EstadoTenant::ACTIVO,
            // Mesma regra da RevogarAcessosDeEscolaSuspensaAction: só escolas já Suspensas.
            'revogar_acessos' => $estado === EstadoTenant::SUSPENSO,
        ];
    }
}
