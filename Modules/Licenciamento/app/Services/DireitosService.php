<?php

namespace Modules\Licenciamento\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Tenancy\TenantContext;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Assinatura\Exceptions\LicencaInvalida;
use Modules\Licenciamento\Documento\DireitosDaLicenca;
use Modules\Licenciamento\Enums\ModoDeOperacao;
use Modules\Licenciamento\Models\DireitosLocais;

/**
 * Direitos do tenant actual. A BD só guarda o token: a assinatura é REVERIFICADA a cada carga (memoizada por pedido),
 * por isso editar as colunas (ou trocar o token por um alheio) nunca concede direitos.
 */
class DireitosService
{
    public const MEMORIA = 'licenciamento.direitos';

    public function __construct(private readonly TenantContext $contexto, private readonly CodecoDeLicenca $codec) {}

    public function doTenantActual(): ?DireitosDaLicenca
    {
        if (! $this->contexto->temTenant()) {
            return null;
        }

        return $this->contexto->lembrar(self::MEMORIA, fn () => $this->carregar());
    }

    public function modo(): ModoDeOperacao
    {
        if (! config('licenciamento.activo')) {
            return ModoDeOperacao::NORMAL;
        }

        return AvaliadorDeLicenca::modo(
            $this->doTenantActual(),
            CarbonImmutable::now('UTC'),
            (int) config('licenciamento.aviso_dias_padrao', 30),
            $this->revogadaDoTenantActual(),
        );
    }

    /**
     * Dados do aviso mostrado ao administrador (null fora do modo AVISO): `bloqueia_em` é o fim da tolerância (ISO 8601, UTC)
     * e `dias_restantes` conta dias inteiros por defeito até lá (0 no último dia).
     *
     * @return array{modo: string, bloqueia_em: string, dias_restantes: int}|null
     */
    public function avisoDeExpiracao(): ?array
    {
        if ($this->modo() !== ModoDeOperacao::AVISO || ($direitos = $this->doTenantActual()) === null) {
            return null;
        }

        $limite = $direitos->bloqueiaEm();

        return [
            'modo' => ModoDeOperacao::AVISO->value,
            'bloqueia_em' => $limite->toIso8601String(),
            'dias_restantes' => max(0, (int) floor(CarbonImmutable::now('UTC')->diffInDays($limite, false))),
        ];
    }

    /**
     * Aliases dos módulos licenciáveis que o tenant actual pode usar. Flag inactiva: todos (nada é restringido).
     * Activa: os que a licença traz, só dentro da lista licenciável; sem licença válida ou revogada, nenhum.
     * Só aliases; nunca datas nem limites (é o que o frontend recebe).
     *
     * @return list<string>
     */
    public function modulosContratados(): array
    {
        $licenciaveis = array_values(array_filter((array) config('licenciamento.modulos_licenciaveis', []), 'is_string'));

        if (! config('licenciamento.activo')) {
            return $licenciaveis;
        }

        $direitos = $this->revogadaDoTenantActual() ? null : $this->doTenantActual();

        return $direitos === null ? [] : array_values(array_filter($licenciaveis, $direitos->temModulo(...)));
    }

    /** Módulo base (fora da lista licenciável) está sempre disponível; um licenciável só se contratado. */
    public function moduloDisponivel(string $alias): bool
    {
        if (! in_array($alias, (array) config('licenciamento.modulos_licenciaveis', []), true)) {
            return true;
        }

        return in_array($alias, $this->modulosContratados(), true);
    }

    public function revogadaDoTenantActual(): bool
    {
        if (! $this->contexto->temTenant()) {
            return false;
        }

        return $this->contexto->lembrar(self::MEMORIA.'.revogada', fn () => DireitosLocais::query()
            ->where('tenant_id', $this->contexto->id())
            ->whereNotNull('revogada_em')
            ->exists());
    }

    /** Esquece a memória do pedido (após instalar/revogar). */
    public function esquecer(): void
    {
        $this->contexto->esquecer(self::MEMORIA);
        $this->contexto->esquecer(self::MEMORIA.'.revogada');
    }

    private function carregar(): ?DireitosDaLicenca
    {
        $tenant = $this->contexto->atual();
        $token = DireitosLocais::query()->where('tenant_id', $tenant->id)->value('token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        try {
            $direitos = DireitosDaLicenca::deClaims($this->codec->verificar($token, ChavesPublicas::daConfig()));
        } catch (LicencaInvalida) {
            return null;
        }

        // Um token válido mas de outra escola não serve a esta (cópia entre linhas da BD).
        if ($direitos->escola !== null && $direitos->escola !== $tenant->codigo) {
            return null;
        }

        if ($direitos->instalacao !== null && $direitos->instalacao !== app(CodigoDaInstalacao::class)->obter()) {
            return null;
        }

        return $direitos;
    }
}
