<?php

namespace Modules\Core\Tests\Unit\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Nwidart\Modules\Facades\Module;
use ReflectionClass;
use Tests\TestCase;

class BaseModuleServiceProviderTest extends TestCase
{
    public function test_todos_os_providers_de_modulo_estendem_a_base_do_core(): void
    {
        foreach (File::glob(base_path('Modules/*/app/Providers/*ServiceProvider.php')) as $ficheiro) {
            if (str_ends_with($ficheiro, 'BaseModuleServiceProvider.php') || ! str_contains(File::get($ficheiro), 'ModuleServiceProvider')) {
                continue;
            }

            $this->assertStringNotContainsString(
                'use Nwidart\Modules\Support\ModuleServiceProvider;',
                File::get($ficheiro),
                "{$ficheiro} deve estender BaseModuleServiceProvider.",
            );
        }
    }

    public function test_modulos_sem_views_nao_registam_namespace_de_views(): void
    {
        $hints = app('view')->getFinder()->getHints();

        $this->assertArrayNotHasKey('aluno', $hints);
        $this->assertArrayNotHasKey('financeiro', $hints);
    }

    public function test_modulos_com_views_continuam_registados(): void
    {
        $hints = app('view')->getFinder()->getHints();

        foreach (['autenticacao', 'matricula', 'permissao', 'turma', 'usuario'] as $modulo) {
            $this->assertArrayHasKey($modulo, $hints);
            foreach ($hints[$modulo] as $caminho) {
                $this->assertDirectoryExists($caminho);
            }
        }
    }

    public function test_view_cache_compila_sem_falhar(): void
    {
        $this->assertSame(0, Artisan::call('view:cache'));
        Artisan::call('view:clear');
    }
}
