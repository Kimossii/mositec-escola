<?php

namespace Modules\Horario\Services;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Contracts\ConsultaDocenciaParaHorario;
use Modules\Core\Enums\TipoHorarioEnum;
use Modules\Core\Models\Horario;
use Modules\Docente\Models\Docente;
use Modules\Docente\Services\DisciplinasAtribuiveisService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Horario\DTO\GrelhaEntradaDTO;
use Modules\Horario\Enums\DiaSemana;
use Modules\Horario\Models\GrelhaEntrada;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\TurnoHorario;

/**
 * Regras da grelha (desenho §5). Deve ser chamado dentro de uma transacção que já bloqueou a linha da turma; não
 * abre transacção própria. O docente não se guarda: o conflito de docente compara os titulares em vigor na data de
 * referência do par (a data de hoje encostada ao intervalo em que as duas vigências se sobrepõem).
 */
class ValidadorGrelhaService
{
    public const MENSAGEM_TURMA_SEM_TURNO = 'A turma não tem turno definido. Defina o turno da turma antes de montar o horário.';

    public const MENSAGEM_SEM_TITULAR = 'Esta disciplina não tem docente titular em vigor nesta turma. Atribua um docente antes de a pôr no horário.';

    public const MENSAGEM_CONFLITO_TURMA = 'A turma já tem uma aula neste dia e tempo durante este período.';

    public const MENSAGEM_CONFLITO_SALA = 'A sala já está ocupada neste dia e tempo durante este período.';

    public const MENSAGEM_CONFLITO_DOCENTE = 'O docente titular desta disciplina já tem aula noutra turma neste dia e tempo.';

    public const MENSAGEM_ANO_ENCERRADO = 'O ano lectivo da turma está encerrado e não aceita alterações ao horário.';

    public function __construct(
        private DisciplinasAtribuiveisService $disciplinas,
        private ConsultaDocenciaParaHorario $docencia,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Devolve a turma validada (com o ano lectivo carregado). */
    public function validar(GrelhaEntradaDTO $dto, ?GrelhaEntrada $ignorar = null): Turma
    {
        $turma = $this->validarTurma($dto);
        $this->validarTempo($dto, $turma);

        if (! $this->disciplinas->daTurma($turma)->contains('id', $dto->disciplina_id)) {
            $this->falhar('disciplina_id', 'A disciplina não faz parte do plano curricular desta turma.');
        }

        $this->validarSala($dto->sala_id);

        $fimAno = $turma->anoLectivo->data_fim->toDateString();
        $inicio = $dto->vigencia_inicio;
        $fim = $dto->vigencia_fim;
        $this->validarVigencia($turma, $inicio, $fim);

        $fimEfectivo = min($fim ?? $fimAno, $fimAno);
        $titular = $this->docencia->titularEm($turma->id, $dto->disciplina_id, $this->referencia($inicio, $fimEfectivo));

        if ($titular === null) {
            $this->falhar('disciplina_id', self::MENSAGEM_SEM_TITULAR);
        }

        $this->validarConflitos($dto, $ignorar, $inicio, $fimEfectivo, $fimAno);

        return $turma;
    }

    /** A turma existe e o ano lectivo dela não está encerrado (para Encerrar/Eliminar, que não revalidam tudo). */
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

    private function validarTurma(GrelhaEntradaDTO $dto): Turma
    {
        $turma = $this->exigirAnoAberto($dto->turma_id);

        if ((int) $turma->estado !== 1) {
            $this->falhar('turma_id', 'A turma está inactiva e não aceita alterações ao horário.');
        }

        if ($turma->turno_id === null) {
            $this->falhar('turma_id', self::MENSAGEM_TURMA_SEM_TURNO);
        }

        return $turma;
    }

    private function validarTempo(GrelhaEntradaDTO $dto, Turma $turma): void
    {
        $tempo = Horario::query()->find($dto->horario_id);

        if ($tempo === null || (int) $tempo->estado !== 1 || $tempo->tipo !== TipoHorarioEnum::TEMPO) {
            $this->falhar('horario_id', 'Escolha um tempo de aula activo (intervalos e períodos não entram na grelha).');
        }

        if (! TurnoHorario::query()->where('turno_id', $turma->turno_id)->where('horario_id', $tempo->id)->exists()) {
            $this->falhar('horario_id', 'Este tempo não pertence ao turno da turma.');
        }

        if (DiaSemana::tryFrom($dto->dia_semana) === null) {
            $this->falhar('dia_semana', 'Escolha um dia entre segunda-feira e sábado.');
        }
    }

    private function validarSala(?int $salaId): void
    {
        if ($salaId === null) {
            return;
        }

        $sala = Sala::query()->find($salaId);

        if ($sala === null || $sala->estado !== EstadoSala::ATIVA) {
            $this->falhar('sala_id', 'A sala indicada não existe ou não está activa.');
        }
    }

    private function validarVigencia(Turma $turma, string $inicio, ?string $fim): void
    {
        $inicioAno = $turma->anoLectivo->data_inicio->toDateString();
        $fimAno = $turma->anoLectivo->data_fim->toDateString();

        if ($inicio < $inicioAno || $inicio > $fimAno) {
            $this->falhar('vigencia_inicio', 'A data de início tem de estar dentro do ano lectivo (' . $this->formatar($inicioAno) . ' a ' . $this->formatar($fimAno) . ').');
        }

        if ($fim === null) {
            return;
        }

        if ($fim < $inicio) {
            $this->falhar('vigencia_fim', 'A data de fim não pode ser anterior à data de início.');
        }

        if ($fim > $fimAno) {
            $this->falhar('vigencia_fim', 'A data de fim tem de estar dentro do ano lectivo (até ' . $this->formatar($fimAno) . ').');
        }
    }

    private function validarConflitos(GrelhaEntradaDTO $dto, ?GrelhaEntrada $ignorar, string $inicio, string $fimEfectivo, string $fimAno): void
    {
        $mesmoSlot = fn () => GrelhaEntrada::query()
            ->where('dia_semana', $dto->dia_semana)
            ->where('horario_id', $dto->horario_id)
            ->sobrepoe($inicio, $fimEfectivo)
            ->when($ignorar !== null, fn ($q) => $q->whereKeyNot($ignorar->id));

        if ($mesmoSlot()->where('turma_id', $dto->turma_id)->exists()) {
            $this->falhar('horario_id', self::MENSAGEM_CONFLITO_TURMA);
        }

        if ($dto->sala_id !== null && $mesmoSlot()->where('sala_id', $dto->sala_id)->exists()) {
            $this->falhar('sala_id', self::MENSAGEM_CONFLITO_SALA);
        }

        $novos = $this->docencia->titularesNoIntervalo($dto->turma_id, $dto->disciplina_id, $inicio, $fimEfectivo);
        $this->serializarPorDocente($novos);

        foreach ($mesmoSlot()->where('turma_id', '!=', $dto->turma_id)->get() as $outra) {
            $inicioComum = max($inicio, $outra->vigencia_inicio->toDateString());
            $fimComum = min($fimEfectivo, $outra->vigencia_fim?->toDateString() ?? $fimAno);
            $outros = $this->docencia->titularesNoIntervalo($outra->turma_id, $outra->disciplina_id, $inicioComum, $fimComum);

            if ($this->mesmoDocenteAoMesmoTempo($novos, $outros)) {
                $this->falhar('disciplina_id', self::MENSAGEM_CONFLITO_DOCENTE);
            }
        }
    }

    /** O mesmo docente é titular dos dois lados em pelo menos um dia (os períodos de titularidade sobrepõem-se). */
    private function mesmoDocenteAoMesmoTempo(array $novos, array $outros): bool
    {
        foreach ($novos as $a) {
            foreach ($outros as $b) {
                if ($a['docente_id'] === $b['docente_id'] && $a['inicio'] <= $b['fim'] && $b['inicio'] <= $a['fim']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Não há constraint de base de dados para o docente: bloqueia as suas linhas (por ordem de id) para que duas
     * gravações do mesmo docente em turmas diferentes sejam validadas uma de cada vez.
     *
     * @param  list<array{docente_id: int, inicio: string, fim: string}>  $titulares
     */
    private function serializarPorDocente(array $titulares): void
    {
        $ids = array_values(array_unique(array_column($titulares, 'docente_id')));

        if ($ids !== []) {
            Docente::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /** Hoje (no fuso da escola) encostado ao intervalo [inicio, fim]. */
    private function referencia(string $inicio, string $fim): CarbonImmutable
    {
        return CarbonImmutable::parse(min(max($this->relogio->hoje()->toDateString(), $inicio), $fim));
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
