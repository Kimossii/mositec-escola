<?php

namespace Modules\Plataforma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Comunicacao\Email\CategoriaEmail;
use Modules\Core\Comunicacao\Email\EstadoEnvioEmail;
use Modules\Core\Models\EnvioEmail;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Plataforma\Tests\Feature\Concerns\ComPainelDaPlataforma;
use Tests\TestCase;

/** Cartão «Falhas de email» do painel: junta as falhas de todas as escolas sem abrir contexto de escola. */
class FalhasDeEmailNoPainelTest extends TestCase
{
    use ComPainelDaPlataforma;
    use RefreshDatabase;

    private string $sessao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
        config(['tenancy.hosts_centrais' => [self::CENTRAL], 'session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->sessao = $this->entrarNoPainel($this->superAdmin());
    }

    private function falhaDaEscola($escola, string $erro, EstadoEnvioEmail $estado = EstadoEnvioEmail::FALHOU, ?string $quando = null): void
    {
        app(TenantContext::class)->executarComo($escola->paraTenantAtual(), function () use ($erro, $estado, $quando) {
            $envio = EnvioEmail::create([
                'notificacao' => 'RecuperarSenhaNotificacao',
                'categoria' => CategoriaEmail::ESSENCIAL,
                'estado' => $estado,
                'destinatario_mascarado' => 'p***@gmail.com',
                'erro' => $erro,
            ]);

            if ($quando) {
                $envio->forceFill(['created_at' => $quando])->saveQuietly();
            }
        });
    }

    public function test_o_painel_mostra_as_falhas_de_todas_as_escolas(): void
    {
        $a = $this->escolaDeGestao('MOSI-900001', 'Escola A', 'a.test');
        $b = $this->escolaDeGestao('MOSI-900002', 'Escola B', 'b.test');
        $this->falhaDaEscola($a, 'Connection refused');
        $this->falhaDaEscola($b, 'Mailbox full');
        $this->falhaDaEscola($b, '', EstadoEnvioEmail::ENVIADO);
        $this->falhaDaEscola($a, 'Antiga', quando: now()->subDays(30)->toDateTimeString());

        $resposta = $this->noPainel('GET', '/plataforma', $this->sessao, cabecalhos: $this->cabecalhosInertia());

        $this->assertSame(2, $resposta->json('props.emailFalhas.total'));
        $recentes = collect($resposta->json('props.emailFalhas.recentes'));
        $this->assertCount(3, $recentes);
        $this->assertEqualsCanonicalizing(['MOSI-900001', 'MOSI-900002', 'MOSI-900001'], $recentes->pluck('escola')->all());
        $this->assertSame('Escola B', $recentes->firstWhere('erro', 'Mailbox full')['escola_nome']);
        $this->assertSame('Central', $recentes->first()['canal']);
        $this->assertArrayNotHasKey('destinatario_mascarado', $recentes->first());
    }

    public function test_sem_falhas_o_cartao_vem_vazio(): void
    {
        $resposta = $this->noPainel('GET', '/plataforma', $this->sessao, cabecalhos: $this->cabecalhosInertia());

        $this->assertSame(0, $resposta->json('props.emailFalhas.total'));
        $this->assertSame([], $resposta->json('props.emailFalhas.recentes'));
    }
}
