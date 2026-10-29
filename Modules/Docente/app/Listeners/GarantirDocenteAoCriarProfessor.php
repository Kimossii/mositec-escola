<?php

namespace Modules\Docente\Listeners;

use Modules\Docente\Actions\GarantirDocenteDeUtilizadorAction;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Events\PerfilAtribuidoAUtilizador;
use Modules\Usuario\Events\UtilizadorCriado;

/** Decisão E7: quem cria (ou edita para) um utilizador Professor pelo fluxo de utilizadores ganha também o registo de docente. */
class GarantirDocenteAoCriarProfessor
{
    public function __construct(private GarantirDocenteDeUtilizadorAction $garantir)
    {
    }

    public function handle(UtilizadorCriado|PerfilAtribuidoAUtilizador $evento): void
    {
        if ($evento->perfil === Perfil::PROFESSOR) {
            $this->garantir->executar($evento->utilizador);
        }
    }
}
