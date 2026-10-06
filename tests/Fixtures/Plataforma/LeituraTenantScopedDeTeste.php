<?php

namespace Tests\Fixtures\Plataforma;

use Modules\Curso\Models\Curso;

/**
 * Rota-fixture: lê um model tenant-scoped. Registada só nos testes, dentro do grupo `plataforma`
 * (tem de falhar alto, sem contexto) e do grupo `web` (controlo positivo).
 */
class LeituraTenantScopedDeTeste
{
    public function __invoke(): string
    {
        return 'cursos:' . Curso::count();
    }
}
