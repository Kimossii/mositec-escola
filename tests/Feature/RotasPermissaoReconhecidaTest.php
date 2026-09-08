<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Services\PermissionResolver;
use Tests\TestCase;

class RotasPermissaoReconhecidaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);
    }

    public function test_todas_as_rotas_can_modulo_acao_resolvem_para_um_modulo_e_uma_acao_reais(): void
    {
        $resolver = app(PermissionResolver::class);
        $naoReconhecidas = [];

        foreach (Route::getRoutes() as $rota) {
            foreach ($rota->middleware() as $middleware) {
                if (!str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $ability = substr($middleware, strlen('can:'));

                if (!str_contains($ability, '.')) {
                    continue; // ability antiga sem ponto (ex: 'gerir-permissoes'), fora de âmbito deste teste
                }

                if (!$resolver->reconhece($ability)) {
                    $naoReconhecidas[] = "{$rota->uri()} => {$ability}";
                }
            }
        }

        $this->assertEmpty($naoReconhecidas, "Rotas com ability modulo.acao não reconhecida:\n" . implode("\n", $naoReconhecidas));
    }
}
