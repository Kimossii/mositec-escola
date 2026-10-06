<?php

namespace Modules\Matricula\Services;

use Modules\Core\Services\GeradorSequencia;
use Modules\Matricula\Models\MatriculaRegistoSequencia;

class GeradorNumeroRegistoMatriculaService
{
    public function __construct(private ?GeradorSequencia $gerador = null)
    {
    }

    public function gerar(): string
    {
        return ($this->gerador ?? app(GeradorSequencia::class))->gerar(MatriculaRegistoSequencia::class);
    }
}
