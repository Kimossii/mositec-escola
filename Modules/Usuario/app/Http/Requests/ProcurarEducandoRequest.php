<?php

namespace Modules\Usuario\Http\Requests;

class ProcurarEducandoRequest extends ProcurarAlunoPorMatriculaRequest
{
    /** Quem cria ou edita utilizadores (liga educandos no cadastro ou na edição de um encarregado). */
    public function authorize(): bool
    {
        $utilizador = $this->user();

        return $utilizador !== null && ($utilizador->can('usuario.criar') || $utilizador->can('usuario.editar'));
    }
}
