<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Comunicacao\Email\EncriptacaoSmtp;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Models\ConfiguracaoEmail;
use Tests\TestCase;

class ConfiguracaoEmailModelTest extends TestCase
{
    use RefreshDatabase;

    private function criar(array $extra = []): ConfiguracaoEmail
    {
        return ConfiguracaoEmail::create($extra + [
            'servidor' => 'smtp.exemplo.com',
            'porta' => 587,
            'encriptacao' => EncriptacaoSmtp::STARTTLS,
            'utilizador' => 'conta',
            'senha' => 'segredo-limpo-123',
            'remetente_endereco' => 'escola@exemplo.com',
        ]);
    }

    public function test_senha_fica_cifrada_na_base_e_escondida_na_serializacao(): void
    {
        $c = $this->criar();

        $naBase = DB::table('configuracoes_email')->where('id', $c->id)->value('senha');
        $this->assertNotSame('segredo-limpo-123', $naBase);
        $this->assertStringNotContainsString('segredo-limpo-123', (string) $naBase);
        $this->assertSame('segredo-limpo-123', $c->fresh()->senha);
        $this->assertArrayNotHasKey('senha', $c->fresh()->toArray());
    }

    public function test_estado_descricao_e_encriptacao_descricao_sincronizam_ao_gravar(): void
    {
        $c = $this->criar();
        $this->assertSame('Por verificar', $c->estado_descricao);
        $this->assertSame('STARTTLS', $c->encriptacao_descricao);

        $c->update(['estado' => EstadoConfiguracaoEmail::COM_FALHA, 'encriptacao' => EncriptacaoSmtp::SSL]);

        $this->assertSame('Com falha', $c->fresh()->estado_descricao);
        $this->assertSame('SSL/TLS', $c->fresh()->encriptacao_descricao);
    }

    public function test_um_tenant_so_pode_ter_uma_configuracao(): void
    {
        $this->criar();

        $this->expectException(QueryException::class);
        $this->criar();
    }

    public function test_estaEmUso_so_com_verificado_e_activo(): void
    {
        $c = $this->criar();
        $this->assertFalse($c->estaEmUso());

        $c->forceFill(['estado' => EstadoConfiguracaoEmail::VERIFICADO, 'activo' => false]);
        $this->assertFalse($c->estaEmUso());

        $c->forceFill(['estado' => EstadoConfiguracaoEmail::COM_FALHA, 'activo' => true]);
        $this->assertFalse($c->estaEmUso());

        $c->forceFill(['estado' => EstadoConfiguracaoEmail::VERIFICADO, 'activo' => true]);
        $this->assertTrue($c->estaEmUso());
    }
}
