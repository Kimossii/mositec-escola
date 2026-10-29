<?php

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Blade;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Base de todos os providers de módulo.
 *
 * O registerViews() do Laravel Modules regista sempre o caminho
 * resources/views, mesmo quando não existe. Em módulos Inertia/Vue (sem Blade)
 * isso faz `view:cache` (e portanto `optimize`) falhar com
 * DirectoryNotFoundException. Aqui o registo de views só acontece quando o
 * módulo tem mesmo views (próprias ou publicadas em resources/views/modules).
 */
abstract class BaseModuleServiceProvider extends ModuleServiceProvider
{
    protected function registerViews(): void
    {
        $temViews = is_dir(module_path($this->name, config('modules.paths.generator.views.path')))
            || is_dir(resource_path('views/modules/'.$this->nameLower));

        if ($temViews) {
            parent::registerViews();

            return;
        }

        // Componentes Blade baseados em classe não dependem da pasta de views.
        Blade::componentNamespace(config('modules.namespace').'\\'.$this->name.'\\View\\Components', $this->nameLower);
    }
}
