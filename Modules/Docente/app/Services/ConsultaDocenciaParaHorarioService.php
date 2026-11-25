<?php

namespace Modules\Docente\Services;

use Carbon\CarbonInterface;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Usuario\Models\User;

/** Leitura da docência para o módulo Horario. Só consulta. */
class ConsultaDocenciaParaHorarioService implements ConsultaDocenciaParaHorario
{
    public function titularEm(int $turmaId, int $disciplinaId, CarbonInterface $data): ?array
    {
        $atribuicao = AtribuicaoDocente::query()
            ->emVigorEfectivo($data)
            ->where('turma_id', $turmaId)
            ->where('disciplina_id', $disciplinaId)
            ->where('papel', PapelDocente::TITULAR->value)
            ->with('docente.dadosPessoa:id,nome_completo')
            ->first();

        if ($atribuicao === null) {
            return null;
        }

        return [
            'docente_id' => (int) $atribuicao->docente_id,
            'nome' => (string) $atribuicao->docente?->dadosPessoa?->nome_completo,
        ];
    }

    public function titularesNoIntervalo(int $turmaId, int $disciplinaId, string $inicio, string $fim): array
    {
        return AtribuicaoDocente::query()
            ->where('turma_id', $turmaId)
            ->where('disciplina_id', $disciplinaId)
            ->where('papel', PapelDocente::TITULAR->value)
            ->whereDate('data_inicio', '<=', $fim)
            ->where(fn ($q) => $q->whereNull('data_fim')->orWhereDate('data_fim', '>=', $inicio))
            ->whereHas('turma')
            ->orderBy('data_inicio')
            ->orderBy('id')
            ->get()
            ->map(fn (AtribuicaoDocente $a) => [
                'docente_id' => (int) $a->docente_id,
                'inicio' => max($inicio, $a->data_inicio->toDateString()),
                'fim' => min($fim, $a->data_fim?->toDateString() ?? $fim),
            ])
            ->values()
            ->all();
    }

    public function paresEmVigorDoDocente(int $docenteId, CarbonInterface $data): array
    {
        return AtribuicaoDocente::query()
            ->emVigorEfectivo($data)
            ->where('docente_id', $docenteId)
            ->get(['turma_id', 'disciplina_id'])
            ->map(fn (AtribuicaoDocente $a) => ['turma_id' => (int) $a->turma_id, 'disciplina_id' => (int) $a->disciplina_id])
            ->unique(fn (array $par) => $par['turma_id'] . '-' . $par['disciplina_id'])
            ->values()
            ->all();
    }

    public function paresEmVigorDoUtilizador(int $utilizadorId, CarbonInterface $data): array
    {
        $docenteId = $this->docenteDoUtilizador($utilizadorId);

        return $docenteId === null ? [] : $this->paresEmVigorDoDocente($docenteId, $data);
    }

    /** O docente do tenant com a mesma pessoa que a conta; null se a conta não tiver pessoa ou docente. */
    private function docenteDoUtilizador(int $utilizadorId): ?int
    {
        $pessoaId = User::query()->whereKey($utilizadorId)->value('dados_pessoa_id');

        if ($pessoaId === null) {
            return null;
        }

        $docenteId = Docente::query()->where('dados_pessoa_id', $pessoaId)->value('id');

        return $docenteId === null ? null : (int) $docenteId;
    }

    public function paresDoUtilizadorNoIntervalo(int $utilizadorId, string $inicio, string $fim): array
    {
        $docenteId = $this->docenteDoUtilizador($utilizadorId);

        if ($docenteId === null) {
            return [];
        }

        return AtribuicaoDocente::query()
            ->where('docente_id', $docenteId)
            ->whereDate('data_inicio', '<=', $fim)
            ->where(fn ($q) => $q->whereNull('data_fim')->orWhereDate('data_fim', '>=', $inicio))
            ->whereHas('turma')
            ->orderBy('data_inicio')
            ->orderBy('id')
            ->get()
            ->map(fn (AtribuicaoDocente $a) => [
                'turma_id' => (int) $a->turma_id,
                'disciplina_id' => (int) $a->disciplina_id,
                'inicio' => max($inicio, $a->data_inicio->toDateString()),
                'fim' => min($fim, $a->data_fim?->toDateString() ?? $fim),
            ])
            ->values()
            ->all();
    }

    public function docentesActivos(): array
    {
        return Docente::query()
            ->where('estado', 1)
            ->with('dadosPessoa:id,nome_completo')
            ->get()
            ->map(fn (Docente $d) => ['id' => (int) $d->id, 'nome' => (string) $d->dadosPessoa?->nome_completo])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }
}
