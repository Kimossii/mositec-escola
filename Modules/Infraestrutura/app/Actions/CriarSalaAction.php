<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Models\Sala;
use Modules\Core\Traits\LogService;

class CriarSalaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `criarSemRegisto()`. */
    public function criar(SalaDTO $dto): Sala
    {
        return $this->comLog('Criar sala', $this->contextoDeArgumentos(func_get_args(), 'criar'), fn () => $this->criarSemRegisto($dto));
    }

    private function criarSemRegisto(SalaDTO $dto): Sala
    {
        return Sala::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'capacidade' => $dto->capacidade,
            'localizacao' => $dto->localizacao,
            'observacoes' => $dto->observacoes,
            'estado' => $dto->estado->value,
        ]);
    }
}
