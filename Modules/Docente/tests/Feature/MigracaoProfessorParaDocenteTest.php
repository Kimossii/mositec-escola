<?php

namespace Modules\Docente\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Docente\Tests\Support\CenarioDeDocencia;
use Modules\Docente\Tests\Support\VerificaMigracaoProfessorParaDocente;
use Tests\TestCase;

/** Migração `professor_id` → `docente_id` em SQLite (a versão PostgreSQL está em Pgsql/). */
class MigracaoProfessorParaDocenteTest extends TestCase
{
    use CenarioDeDocencia;
    use RefreshDatabase;
    use VerificaMigracaoProfessorParaDocente;
}
