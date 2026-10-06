<?php

namespace Modules\Usuario\Services;

use Modules\Core\Services\GeradorSequencia;
use Modules\Usuario\Models\MatriculaSequencia;

class GeradorMatriculaService
{
    public function __construct(private ?GeradorSequencia $gerador = null)
    {
    }

    public function gerar(): string
    {
        return ($this->gerador ?? app(GeradorSequencia::class))->gerar(MatriculaSequencia::class);
    }
}
