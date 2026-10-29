<?php

namespace Modules\Docente\Services;

use Modules\Docente\Actions\AtribuirDocenteAction;
use Modules\Docente\Actions\AtualizarAtribuicaoAction;
use Modules\Docente\Actions\EliminarAtribuicaoAction;
use Modules\Docente\Actions\EncerrarAtribuicaoAction;
use Modules\Docente\Actions\SubstituirTitularAction;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\DTO\SubstituicaoTitularDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Http\Requests\AtribuirDocenteRequest;
use Modules\Docente\Http\Requests\AtualizarAtribuicaoRequest;
use Modules\Docente\Http\Requests\EncerrarAtribuicaoRequest;
use Modules\Docente\Http\Requests\SubstituirTitularRequest;
use Modules\Docente\Models\AtribuicaoDocente;

class GestaoAtribuicaoDocenteService
{
    public function __construct(
        private AtribuirDocenteAction $atribuirDocente,
        private SubstituirTitularAction $substituirTitular,
        private AtualizarAtribuicaoAction $actualizarAtribuicao,
        private EncerrarAtribuicaoAction $encerrarAtribuicao,
        private EliminarAtribuicaoAction $eliminarAtribuicao,
    ) {
    }

    public function atribuir(AtribuirDocenteRequest $request): AtribuicaoDocente
    {
        $dados = $request->validated();

        return $this->atribuirDocente->executar(new AtribuicaoDocenteDTO(
            professor_id: (int) $dados['professor_id'],
            turma_id: (int) $dados['turma_id'],
            disciplina_id: (int) $dados['disciplina_id'],
            papel: PapelDocente::from((int) $dados['papel']),
            data_inicio: $dados['data_inicio'],
            data_fim: $dados['data_fim'] ?? null,
        ));
    }

    public function substituir(SubstituirTitularRequest $request): AtribuicaoDocente
    {
        $dados = $request->validated();

        return $this->substituirTitular->executar(new SubstituicaoTitularDTO(
            turma_id: (int) $dados['turma_id'],
            disciplina_id: (int) $dados['disciplina_id'],
            novo_professor_id: (int) $dados['novo_professor_id'],
            ultimo_dia_do_titular: $dados['ultimo_dia_do_titular'],
            motivo: $dados['motivo'],
        ));
    }

    public function actualizar(AtribuicaoDocente $atribuicao, AtualizarAtribuicaoRequest $request): AtribuicaoDocente
    {
        $dados = $request->validated();

        return $this->actualizarAtribuicao->executar(
            $atribuicao,
            PapelDocente::from((int) $dados['papel']),
            $dados['data_inicio'],
            $dados['data_fim'] ?? null,
        );
    }

    public function encerrar(AtribuicaoDocente $atribuicao, EncerrarAtribuicaoRequest $request): AtribuicaoDocente
    {
        $dados = $request->validated();

        return $this->encerrarAtribuicao->executar($atribuicao, $dados['data_fim'], $dados['motivo']);
    }

    public function eliminar(AtribuicaoDocente $atribuicao): void
    {
        $this->eliminarAtribuicao->executar($atribuicao);
    }
}
