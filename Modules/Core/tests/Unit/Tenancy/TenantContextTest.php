<?php

namespace Modules\Core\Tests\Unit\Tenancy;

use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TenantContextTest extends TestCase
{
    private function tenant(int $id): TenantAtual
    {
        return new TenantAtual($id, sprintf('MOSI-%06d', $id), "Escola {$id}", EstadoTenant::ACTIVO);
    }

    public function test_sem_tenant_atual_lanca_excepcao(): void
    {
        $contexto = new TenantContext();

        $this->assertFalse($contexto->temTenant());
        $this->expectException(TenantNaoResolvido::class);
        $contexto->atual();
    }

    public function test_id_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);
        (new TenantContext())->id();
    }

    public function test_definir_e_limpar(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(7));

        $this->assertTrue($contexto->temTenant());
        $this->assertSame(7, $contexto->id());
        $this->assertSame('MOSI-000007', $contexto->atual()->codigo);

        $contexto->limpar();
        $this->assertFalse($contexto->temTenant());
    }

    public function test_executar_como_devolve_o_resultado_e_repoe_o_contexto_anterior(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));

        $resultado = $contexto->executarComo($this->tenant(2), fn () => $contexto->id());

        $this->assertSame(2, $resultado);
        $this->assertSame(1, $contexto->id());
    }

    public function test_executar_como_sem_contexto_anterior_volta_a_ficar_sem_tenant(): void
    {
        $contexto = new TenantContext();

        $contexto->executarComo($this->tenant(2), fn () => null);

        $this->assertFalse($contexto->temTenant());
    }

    public function test_executar_como_repoe_o_contexto_mesmo_com_excepcao(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));

        try {
            $contexto->executarComo($this->tenant(2), fn () => throw new RuntimeException('falhou'));
            $this->fail('Devia ter lançado a excepção.');
        } catch (RuntimeException $e) {
            $this->assertSame('falhou', $e->getMessage());
        }

        $this->assertSame(1, $contexto->id());
    }

    public function test_lembrar_calcula_uma_vez_por_tenant(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));
        $chamadas = 0;
        $calcular = function () use (&$chamadas) {
            $chamadas++;

            return null;
        };

        $contexto->lembrar('chave', $calcular);
        $contexto->lembrar('chave', $calcular);

        $this->assertSame(1, $chamadas, 'Um resultado nulo também fica guardado.');
    }

    public function test_lembrar_nao_vaza_entre_tenants(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));
        $contexto->lembrar('nome', fn () => 'do tenant 1');

        $dentro = $contexto->executarComo($this->tenant(2), fn () => $contexto->lembrar('nome', fn () => 'do tenant 2'));

        $this->assertSame('do tenant 2', $dentro);
        $this->assertSame('do tenant 1', $contexto->lembrar('nome', fn () => 'recalculado'));
    }

    public function test_lembrar_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);
        (new TenantContext())->lembrar('chave', fn () => 1);
    }

    public function test_definir_outro_tenant_limpa_a_memoria(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));
        $contexto->lembrar('nome', fn () => 'do tenant 1');

        $contexto->definir($this->tenant(2));

        $this->assertSame('do tenant 2', $contexto->lembrar('nome', fn () => 'do tenant 2'));
    }
}
