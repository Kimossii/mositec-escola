<?php

namespace Modules\Docente\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Models\Docente;
use Modules\Turma\Models\Turma;

/**
 * Regras 1 a 6 do desenho (docente, turma/ano, disciplina atribuível, datas, sobreposição).
 * Deve ser chamado dentro de uma transacção que já bloqueou a linha da turma; não abre transacção própria.
 */
class ValidadorAtribuicaoDocenteService
{
    /** Regra 5: reutilizada pela Action quando a constraint de exclusão do PostgreSQL recusa (concorrência). */
    public const MENSAGEM_TITULAR_SOBREPOSTO = 'Já existe um titular em vigor nesta turma e disciplina durante este período. Use "Substituir titular".';

    /** Regra 6: idem. */
    public const MENSAGEM_DOCENTE_SOBREPOSTO = 'Este docente já tem uma atribuição nesta turma e disciplina que se sobrepõe ao período indicado.';

    public const MENSAGEM_ANO_ENCERRADO = 'O ano lectivo da turma está encerrado e não aceita atribuições de docentes.';

    public function __construct(private DisciplinasAtribuiveisService $disciplinas)
    {
    }

    /** Devolve a turma validada (com o ano lectivo carregado). */
    public function validar(AtribuicaoDocenteDTO $dto, ?AtribuicaoDocente $ignorar = null): Turma
    {
        $this->validarDocente($dto->docente_id);

        $turma = Turma::query()->with('anoLectivo')->find($dto->turma_id);

        if ($turma === null) {
            $this->falhar('turma_id', 'A turma indicada não existe.');
        }

        if ((int) $turma->estado !== 1) {
            $this->falhar('turma_id', 'A turma está inactiva e não aceita atribuições de docentes.');
        }

        $ano = $turma->anoLectivo;

        if ($ano === null || $ano->estado === EstadoAnoLectivo::ENCERRADO) {
            $this->falhar('turma_id', self::MENSAGEM_ANO_ENCERRADO);
        }

        if (! $this->disciplinas->daTurma($turma)->contains('id', $dto->disciplina_id)) {
            $this->falhar('disciplina_id', 'A disciplina não faz parte do plano curricular desta turma.');
        }

        $inicioAno = $ano->data_inicio->toDateString();
        $fimAno = $ano->data_fim->toDateString();
        $inicio = $dto->data_inicio;
        $fim = $dto->data_fim;

        if ($inicio < $inicioAno || $inicio > $fimAno) {
            $this->falhar('data_inicio', 'A data de início tem de estar dentro do ano lectivo (' . $this->formatar($inicioAno) . ' a ' . $this->formatar($fimAno) . ').');
        }

        if ($fim !== null) {
            if ($fim < $inicio) {
                $this->falhar('data_fim', 'A data de fim não pode ser anterior à data de início.');
            }

            if ($fim > $fimAno) {
                $this->falhar('data_fim', 'A data de fim tem de estar dentro do ano lectivo (até ' . $this->formatar($fimAno) . ').');
            }
        }

        $this->validarSobreposicao($dto, $inicio, min($fim ?? $fimAno, $fimAno), $fimAno, $ignorar);

        return $turma;
    }

    /**
     * Bloqueio comum a substituir, encerrar, actualizar e eliminar: a turma tem de existir e o seu ano lectivo
     * não pode estar encerrado. Chamar dentro da transacção que já bloqueou a turma.
     */
    public function exigirAnoAberto(int $turmaId): Turma
    {
        $turma = Turma::query()->with('anoLectivo')->find($turmaId);

        if ($turma === null) {
            $this->falhar('turma_id', 'A turma indicada não existe.');
        }

        if ($turma->anoLectivo === null || $turma->anoLectivo->estado === EstadoAnoLectivo::ENCERRADO) {
            $this->falhar('turma_id', self::MENSAGEM_ANO_ENCERRADO);
        }

        return $turma;
    }

    /** O docente tem de existir no tenant (scope do model) e estar activo; com ou sem conta, seja qual for o perfil. */
    private function validarDocente(int $docenteId): void
    {
        // FOR SHARE: enquanto a atribuição não grava, um «eliminar docente» concorrente (que bloqueia a linha e confere as
        // atribuições) espera, e vice-versa; sem isto a gravação falhava com uma violação da FK em vez de uma validação.
        $docente = Docente::query()->whereKey($docenteId)->sharedLock()->first();

        if ($docente === null) {
            $this->falhar('docente_id', 'O docente indicado não existe.');
        }

        if ((int) $docente->estado !== 1) {
            $this->falhar('docente_id', 'O docente indicado está inactivo.');
        }
    }

    /** Regra 5 (titular contra titular) e regra 6 (mesmo docente, qualquer papel). Extremos inclusivos. */
    private function validarSobreposicao(AtribuicaoDocenteDTO $dto, string $inicio, string $fim, string $fimAno, ?AtribuicaoDocente $ignorar): void
    {
        $existentes = AtribuicaoDocente::query()
            ->where('turma_id', $dto->turma_id)
            ->where('disciplina_id', $dto->disciplina_id)
            ->when($ignorar !== null, fn ($q) => $q->where('id', '!=', $ignorar->id))
            ->get();

        foreach ($existentes as $existente) {
            $inicioExistente = $existente->data_inicio->toDateString();
            $fimExistente = min($existente->data_fim?->toDateString() ?? $fimAno, $fimAno);

            if ($inicioExistente > $fim || $fimExistente < $inicio) {
                continue;
            }

            if ((int) $existente->docente_id === (int) $dto->docente_id) {
                $this->falhar('docente_id', self::MENSAGEM_DOCENTE_SOBREPOSTO);
            }

            if ($dto->papel === PapelDocente::TITULAR && $existente->papel === PapelDocente::TITULAR) {
                $this->falhar('data_inicio', self::MENSAGEM_TITULAR_SOBREPOSTO);
            }
        }
    }

    private function formatar(string $data): string
    {
        return CarbonImmutable::parse($data)->format('d/m/Y');
    }

    private function falhar(string $chave, string $mensagem): never
    {
        throw ValidationException::withMessages([$chave => $mensagem]);
    }
}
