<?php

namespace Modules\Core\Tests\Unit\Providers;

use Illuminate\Foundation\Auth\User;
use Modules\Core\Contracts\IdentificaAdministradorDaEscola;
use Modules\Core\Contracts\NinguemEAdministrador;
use Tests\TestCase;

class NinguemEAdministradorTest extends TestCase
{
    public function test_a_implementacao_nula_nunca_identifica_administradores(): void
    {
        $this->assertFalse((new NinguemEAdministrador)->eAdministrador(new User));
    }

    public function test_o_contrato_tem_sempre_binding_resolvivel(): void
    {
        $this->assertInstanceOf(IdentificaAdministradorDaEscola::class, $this->app->make(IdentificaAdministradorDaEscola::class));
    }
}
