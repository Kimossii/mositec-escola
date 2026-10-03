<?php

namespace Tests\Concerns;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait DescobreModels
{
    /**
     * Models da aplicação e de todos os módulos, incluindo subpastas de Models/.
     *
     * @return string[]
     */
    protected function classesDeModels(): array
    {
        $classes = [];
        $raizes = ['App\\Models\\' => base_path('app/Models')];

        foreach (glob(base_path('Modules/*/app/Models')) as $pasta) {
            $raizes['Modules\\' . basename(dirname($pasta, 2)) . '\\Models\\'] = $pasta;
        }

        foreach ($raizes as $namespace => $pasta) {
            if (! is_dir($pasta)) {
                continue;
            }

            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                    $relativo = substr($ficheiro->getPathname(), strlen($pasta) + 1, -4);
                    $classes[] = $namespace . str_replace('/', '\\', $relativo);
                }
            }
        }

        return $classes;
    }
}
