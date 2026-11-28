<?php

namespace Modules\Plataforma\Services;

use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Comunicacao\Email\CanalEnvioEmail;
use Modules\Core\Comunicacao\Email\ConsumoDeEmail;
use Modules\Core\Models\ConfiguracaoEmail;
use Modules\Core\Models\ConsumoEmailMensal;
use Modules\Core\Models\DominioEmail;
use Modules\Core\Services\ConsumoDeEmailConsultaService;
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
     * @return list<array{tipo: string, escola: ?string, escola_nome: ?string, estado: int, estado_descricao: string, activo: bool, ultimo_erro: ?string}>
     */
    public function infraPorEscola(): array
    {
        $configuracoes = ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->orderByDesc('updated_at')->get(['tenant_id', 'estado', 'estado_descricao', 'activo', 'bloqueada_pela_plataforma', 'ultimo_erro']);
        $escolas = Tenant::query()->whereIn('id', $configuracoes->pluck('tenant_id'))->get(['id', 'codigo', 'nome'])->keyBy('id');

        $dominios = ConsultaEntreEscolas::de(DominioEmail::class)->orderByDesc('updated_at')->get(['tenant_id', 'estado', 'estado_descricao', 'activo', 'bloqueada_pela_plataforma', 'ultimo_erro']);
        $escolas = $escolas->union(Tenant::query()->whereIn('id', $dominios->pluck('tenant_id'))->get(['id', 'codigo', 'nome'])->keyBy('id'));

        $doDominio = $dominios->map(fn (DominioEmail $d) => [
            'escola' => $escolas[$d->tenant_id]->codigo ?? null,
            'escola_nome' => $escolas[$d->tenant_id]->nome ?? null,
            'tipo' => 'Domínio',
            'estado' => $d->estado->value,
            'estado_descricao' => $d->estado_descricao,
            'activo' => (bool) $d->activo,
            'bloqueada' => (bool) $d->bloqueada_pela_plataforma,
            'ultimo_erro' => $d->ultimo_erro,
        ]);

        return $configuracoes->map(fn (ConfiguracaoEmail $c) => [
            'tipo' => 'Servidor SMTP',
            'escola' => $escolas[$c->tenant_id]->codigo ?? null,
            'escola_nome' => $escolas[$c->tenant_id]->nome ?? null,
            'estado' => $c->estado->value,
            'estado_descricao' => $c->estado_descricao,
            'activo' => (bool) $c->activo,
            'bloqueada' => (bool) $c->bloqueada_pela_plataforma,
            'ultimo_erro' => $c->ultimo_erro,
        ])->concat($doDominio)->values()->all();
    }

    /**
     * As escolas que mais emails enviaram nas últimas 24 horas (para detectar abuso a tempo), com o consumo opcional do mês.
     *
     * @return list<array{escola: ?string, escola_nome: ?string, enviados_24h: int, opcionais_mes: int, canal_proprio: bool}>
     */
    public function volumePorEscola(int $limite = 10): array
    {
        $linhas = ConsultaEntreEscolas::de(EnvioEmail::class)
            ->where('estado', EstadoEnvioEmail::ENVIADO->value)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('tenant_id, count(*) as total, max(canal) as canal')
            ->groupBy('tenant_id')
            ->orderByDesc('total')
            ->limit($limite)
            ->get();

        $ids = $linhas->pluck('tenant_id');
        $escolas = Tenant::query()->whereIn('id', $ids)->get(['id', 'codigo', 'nome'])->keyBy('id');
        $mes = ConsultaEntreEscolas::de(ConsumoEmailMensal::class)->whereIn('tenant_id', $ids)->where('mes', ConsumoDeEmail::mesActual())->pluck('opcionais', 'tenant_id');

        return $linhas->map(fn ($l) => [
            'escola' => $escolas[$l->tenant_id]->codigo ?? null,
            'escola_nome' => $escolas[$l->tenant_id]->nome ?? null,
            'enviados_24h' => (int) $l->total,
            'opcionais_mes' => (int) ($mes[$l->tenant_id] ?? 0),
            'canal_proprio' => (int) $l->getRawOriginal('canal') === CanalEnvioEmail::PROPRIO->value,
        ])->values()->all();
    }

    /**
     * Consumo do mês de emails opcionais de uma escola, contra o limite da licença dela (lido sem contexto de escola).
     *
     * @return array{mes: string, usado: int, limite: ?int, percentagem: ?int, estado: string}
     */
    public function consumoDaEscola(Tenant $tenant, ?int $limite, int $avisoPct): array
    {
        $mes = ConsumoDeEmail::mesActual();
        $usado = (int) (ConsultaEntreEscolas::de(ConsumoEmailMensal::class)->where('tenant_id', $tenant->id)->where('mes', $mes)->value('opcionais') ?? 0);

        return ConsumoDeEmailConsultaService::resumo($mes, $usado, $limite, $avisoPct);
    }

    /**
     * Estado do servidor próprio da escola para o botão da Plataforma: a enviar agora? bloqueada?
     *
     * @return array{activo: bool, bloqueada: bool}
     */
    public function estadoDoServidorProprio(Tenant $tenant): array
    {
        $c = ConsultaEntreEscolas::de(ConfiguracaoEmail::class)->where('tenant_id', $tenant->id)->first(['activo', 'bloqueada_pela_plataforma']);
        $d = ConsultaEntreEscolas::de(DominioEmail::class)->where('tenant_id', $tenant->id)->first(['activo', 'bloqueada_pela_plataforma']);

        return [
            'activo' => (bool) ($c?->activo || $d?->activo),
            'bloqueada' => (bool) ($c?->bloqueada_pela_plataforma || $d?->bloqueada_pela_plataforma),
        ];
    }
}
