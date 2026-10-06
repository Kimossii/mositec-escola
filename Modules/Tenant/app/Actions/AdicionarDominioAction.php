<?php

namespace Modules\Tenant\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Exceptions\TransicaoDeEstadoInvalida;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\ClassificadorDominio;
use Modules\Tenant\Services\ValidadorDominio;

/**
 * Acrescenta um domínio (não principal) a um tenant, por exemplo um domínio personalizado
 * (spec §5.4). A verificação de propriedade do domínio fica fora do âmbito.
 */
class AdicionarDominioAction
{
    public function __construct(
        private readonly ValidadorDominio $validador,
        private readonly ClassificadorDominio $classificador,
    ) {}

    /**
     * @throws DadosDeTenantInvalidos
     * @throws TransicaoDeEstadoInvalida
     */
    public function executar(Tenant $tenant, string $dominio): Domain
    {
        $host = $this->validador->validar($dominio);

        try {
            return DB::transaction(function () use ($tenant, $host) {
                $actual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);

                if ($actual->estado === EstadoTenant::ENCERRADO) {
                    throw TransicaoDeEstadoInvalida::para($actual->codigo, $actual->estado, 'adicionar um domínio');
                }

                return $actual->dominios()->create([
                    'dominio' => $host,
                    'tipo' => $this->classificador->classificar($host),
                    'is_principal' => false,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new DadosDeTenantInvalidos(['dominio' => "O domínio '{$host}' foi registado por outro pedido em simultâneo."]);
        }
    }
}
