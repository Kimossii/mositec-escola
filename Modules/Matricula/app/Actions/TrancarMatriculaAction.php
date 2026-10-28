<?php

namespace Modules\Matricula\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\VerificaTrancamentoMatricula;
use Modules\Core\DTO\ResultadoVerificacaoTrancamento;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Core\Traits\LogService;

/**
 * Tranca uma matrícula Activa. A verificação (ex.: regularização financeira) vem de implementações etiquetadas do
 * contrato do Core, na mesma transacção e com a matrícula bloqueada. Bloqueios impedem; avisos que pedem confirmação
 * (detalhe['confirmar']) exigem `confirmarComDividas`. A data de efeito é retroactiva até ao último evento da
 * matrícula, nunca futura.
 */
class TrancarMatriculaAction
{
    use LogService;

    public function __construct(
        private RegistarHistoricoMatriculaAction $registarHistorico,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Matricula $matricula, string $dataEfeito, string $motivo, bool $confirmarComDividas = false, ?int $utilizadorId = null): Matricula
    {
        return $this->comLog('Trancar matrícula', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula, $dataEfeito, $motivo, $confirmarComDividas, $utilizadorId));
    }

    private function executarSemRegisto(Matricula $matricula, string $dataEfeito, string $motivo, bool $confirmarComDividas = false, ?int $utilizadorId = null): Matricula
    {
        return DB::transaction(function () use ($matricula, $dataEfeito, $motivo, $confirmarComDividas, $utilizadorId) {
            $bloqueada = Matricula::query()->whereKey($matricula->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueada->estado !== EstadoMatriculaEnum::ACTIVA) {
                throw ValidationException::withMessages(['estado' => 'Só se pode trancar uma matrícula activa.']);
            }

            $data = $this->dataValidada($bloqueada, $dataEfeito);
            $motivo = trim($motivo);

            if (mb_strlen($motivo) < 5) {
                throw ValidationException::withMessages(['motivo' => 'Indique o motivo do trancamento (mínimo 5 caracteres).']);
            }

            $resultado = $this->verificar($bloqueada, $data);

            if ($resultado->bloqueia()) {
                throw ValidationException::withMessages(['estado' => $resultado->bloqueios[0]['mensagem']]);
            }

            $pedeConfirmacao = collect($resultado->avisos)->contains(fn (array $a) => ($a['detalhe']['confirmar'] ?? false) === true);

            if ($pedeConfirmacao && ! $confirmarComDividas) {
                throw ValidationException::withMessages(['confirmar_com_dividas' => 'Confirme que pretende trancar com dívidas em aberto.']);
            }

            $bloqueada->estado = EstadoMatriculaEnum::TRANCADA;
            $bloqueada->editado_por = $utilizadorId;
            $bloqueada->save();

            $this->registarHistorico->executar(
                $bloqueada,
                EstadoMatriculaEnum::ACTIVA,
                EstadoMatriculaEnum::TRANCADA,
                $utilizadorId,
                $data->toDateString(),
                $motivo,
                ['avisos' => $resultado->avisos, 'confirmou_com_dividas' => $pedeConfirmacao && $confirmarComDividas],
            );

            MatriculaEstadoAlterado::dispatch((int) $bloqueada->id, EstadoMatriculaEnum::ACTIVA->value, EstadoMatriculaEnum::TRANCADA->value, $data->toDateString(), $utilizadorId);

            return $bloqueada->fresh();
        });
    }

    /** Só leitura: o que a verificação diria para esta data (usada pela pré-visualização do modal). */
    public function verificar(Matricula $matricula, CarbonImmutable $data): ResultadoVerificacaoTrancamento
    {
        $resultado = new ResultadoVerificacaoTrancamento();

        foreach (app()->tagged(VerificaTrancamentoMatricula::ETIQUETA) as $verificacao) {
            $resultado = $resultado->juntar($verificacao->verificar((int) $matricula->id, $data));
        }

        return $resultado;
    }

    private function dataValidada(Matricula $matricula, string $pedida): CarbonImmutable
    {
        $data = CarbonImmutable::parse($pedida)->startOfDay();
        $hoje = $this->relogio->hoje()->startOfDay();
        $ultimoEvento = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->whereNotNull('data_efeito')->max('data_efeito');
        $minimo = max($matricula->data_matricula?->toDateString() ?? '0000-00-00', substr((string) $ultimoEvento, 0, 10));

        if ($data->toDateString() > $hoje->toDateString()) {
            throw ValidationException::withMessages(['data_efeito' => 'A data do trancamento não pode ser futura.']);
        }

        if ($data->toDateString() < $minimo) {
            throw ValidationException::withMessages(['data_efeito' => 'A data do trancamento não pode ser anterior ao último evento da matrícula (' . CarbonImmutable::parse($minimo)->format('d/m/Y') . ').']);
        }

        return $data;
    }
}
