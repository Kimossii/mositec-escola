<?php

namespace Modules\Frequencia\Services;

use Carbon\CarbonInterface;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Docente\Models\Docente;
use Modules\Frequencia\Enums\TipoAula;
use Modules\Usuario\Models\User;

/**
 * Quem pode marcar a chamada: o professor com atribuição em vigor na turma e disciplina (regime tempo) ou na turma
 * (regime dia), titular ou substituto; ou quem tem `frequencia.corrigir-fora-prazo` (secretaria, administração).
 */
class AutorizacaoDeChamadaService
{
    public function __construct(private ConsultaDocenciaParaHorario $docencia)
    {
    }

    public function podeMarcar(User $utilizador, int $turmaId, ?int $disciplinaId, TipoAula $tipo, CarbonInterface $data): bool
    {
        if ($utilizador->can('frequencia.corrigir-fora-prazo')) {
            return true;
        }

        foreach ($this->docencia->paresEmVigorDoUtilizador($utilizador->id, $data) as $par) {
            if ($par['turma_id'] === $turmaId && ($tipo === TipoAula::DIA || $par['disciplina_id'] === $disciplinaId)) {
                return true;
            }
        }

        return false;
    }

    /** O docente da conta (pela pessoa), se existir. */
    public function docenteDoUtilizador(User $utilizador): ?int
    {
        if ($utilizador->dados_pessoa_id === null) {
            return null;
        }

        $id = Docente::query()->where('dados_pessoa_id', $utilizador->dados_pessoa_id)->value('id');

        return $id === null ? null : (int) $id;
    }
}
