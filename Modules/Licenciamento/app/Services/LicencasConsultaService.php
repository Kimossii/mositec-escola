<?php

namespace Modules\Licenciamento\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Licenciamento\Enums\EstadoComercial;
use Modules\Licenciamento\Models\LicencaEmitida;
use Modules\Licenciamento\Models\Plano;

/**
 * Leitura do lado do emissor (planos e licenças emitidas) para o painel. Só toca em tabelas globais
 * `licenciamento_*`: pode correr sem contexto de tenant. O token nunca sai daqui senão pelo modelo.
 */
class LicencasConsultaService
{
    public const VALIDA = 'valida';

    public const A_EXPIRAR = 'a_expirar';

    public const EM_TOLERANCIA = 'em_tolerancia';

    public const EXPIRADA = 'expirada';

    public const NAO_INICIADA = 'nao_iniciada';

    public const SUSPENSA = 'suspensa';

    public const CANCELADA = 'cancelada';

    public const SUBSTITUIDA = 'substituida';

    public function __construct(private readonly CatalogoDeTenants $tenants) {}

    /** @return Collection<int, Plano> */
    public function planos(): Collection
    {
        return Plano::query()->orderBy('nome')->orderBy('id')->get();
    }

    /** @return array<int, string> nome de cada plano, por id */
    public function nomesDosPlanos(): array
    {
        return Plano::query()->pluck('nome', 'id')->all();
    }

    /**
     * O que a licença admite no seu estado comercial (espelha as transições das Actions; estas voltam a validar).
     * Renovar e estender precisam também da chave de emissão: quem chama junta essa condição.
     *
     * @return array{renovar: bool, estender: bool, suspender: bool, reactivar: bool, cancelar: bool}
     */
    public function accoesPermitidas(LicencaEmitida $licenca): array
    {
        $activa = $licenca->estado_comercial === EstadoComercial::ACTIVA;

        return [
            'renovar' => $activa,
            'estender' => $activa,
            'suspender' => $activa,
            'reactivar' => $licenca->estado_comercial === EstadoComercial::SUSPENSA,
            'cancelar' => $activa || $licenca->estado_comercial === EstadoComercial::SUSPENSA,
        ];
    }

    /** @return LengthAwarePaginator<int, LicencaEmitida> a mais recente primeiro */
    public function listar(int $porPagina = 15): LengthAwarePaginator
    {
        return LicencaEmitida::query()->orderByDesc('emitida_em')->orderByDesc('id')->paginate($porPagina);
    }

    /**
     * Licenças activas por ordem crescente de expiração (as já expiradas, ainda activas, vêm primeiro: são as mais urgentes).
     *
     * @return Collection<int, LicencaEmitida>
     */
    public function proximasExpiracoes(int $limite = 5): Collection
    {
        return LicencaEmitida::query()
            ->where('estado_comercial', EstadoComercial::ACTIVA->value)
            ->orderBy('expira_em')
            ->orderBy('id')
            ->limit($limite)
            ->get();
    }

    /** A licença em vigor da escola (activa ou suspensa; as substituídas, canceladas e expiradas não contam). */
    public function actualDaEscola(string $codigo): ?LicencaEmitida
    {
        return LicencaEmitida::query()
            ->where('escola', $codigo)
            ->whereIn('estado_comercial', [EstadoComercial::ACTIVA->value, EstadoComercial::SUSPENSA->value])
            ->orderByDesc('emitida_em')
            ->orderByDesc('id')
            ->first();
    }

    /** Escolas desta instalação, para o formulário de emissão SaaS. @return list<array{codigo: string, nome: string}> */
    public function escolasDestaInstalacao(): array
    {
        $escolas = array_map(fn ($tenant) => ['codigo' => $tenant->codigo, 'nome' => $tenant->nome], $this->tenants->todos());
        usort($escolas, fn (array $a, array $b) => strcmp($a['codigo'], $b['codigo']));

        return $escolas;
    }

    /**
     * Validade derivada das datas (e do estado comercial, que é independente e tem prioridade quando já não está activa).
     * Aviso e tolerância são os da própria licença.
     */
    public function validade(LicencaEmitida $licenca, ?CarbonImmutable $agora = null): string
    {
        $agora ??= CarbonImmutable::now()->utc();

        return match ($licenca->estado_comercial) {
            EstadoComercial::SUSPENSA => self::SUSPENSA,
            EstadoComercial::CANCELADA => self::CANCELADA,
            EstadoComercial::SUBSTITUIDA => self::SUBSTITUIDA,
            default => $this->validadePorDatas($licenca, $agora),
        };
    }

    private function validadePorDatas(LicencaEmitida $licenca, CarbonImmutable $agora): string
    {
        $expira = CarbonImmutable::instance($licenca->expira_em);

        if ($agora->lt($licenca->inicio)) {
            return self::NAO_INICIADA;
        }

        if ($agora->gt($expira->addDays((int) $licenca->tolerancia_dias))) {
            return self::EXPIRADA;
        }

        if ($agora->gt($expira)) {
            return self::EM_TOLERANCIA;
        }

        $aviso = (int) ($licenca->aviso_dias ?? config('licenciamento.aviso_dias_padrao'));

        return $agora->gte($expira->subDays($aviso)) ? self::A_EXPIRAR : self::VALIDA;
    }
}
