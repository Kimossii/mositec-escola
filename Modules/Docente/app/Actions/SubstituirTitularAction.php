<?php

namespace Modules\Docente\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Docente\DTO\AtribuicaoDocenteDTO;
use Modules\Docente\DTO\SubstituicaoTitularDTO;
use Modules\Docente\Enums\PapelDocente;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\ValidadorAtribuicaoDocenteService;
use Modules\Docente\Support\SobreposicaoDeAtribuicao;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Turma\Models\Turma;

/**
 * Regra 7 do desenho: termina o titular em vigor no último dia indicado (com motivo) e cria o novo titular no dia
 * seguinte, tudo numa só transacção com a turma bloqueada. Se o novo for inválido, o antigo fica intacto.
 * Não reescreve histórico: um titular já terminado não se altera e o novo começa, no mínimo, hoje (como em Encerrar).
 */
class SubstituirTitularAction
{
    use LogService;

    public function __construct(
        private ValidadorAtribuicaoDocenteService $validador,
        private AtribuirDocenteAction $atribuir,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(SubstituicaoTitularDTO $dto): AtribuicaoDocente
    {
        return $this->comLog('Substituir titular', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($dto));
    }

    private function executarSemRegisto(SubstituicaoTitularDTO $dto): AtribuicaoDocente
    {
        try {
            return DB::transaction(fn () => $this->substituir($dto));
        } catch (QueryException $e) {
            throw SobreposicaoDeAtribuicao::comoErroDeValidacao($e) ?? $e;
        }
    }

    private function substituir(SubstituicaoTitularDTO $dto): AtribuicaoDocente
    {
        Turma::query()->whereKey($dto->turma_id)->lockForUpdate()->first();
        $turma = $this->validador->exigirAnoAberto($dto->turma_id);

        $motivo = trim($dto->motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Indique o motivo da substituição.']);
        }

        $ultimoDia = CarbonImmutable::parse($dto->ultimo_dia_do_titular);
        $titular = $this->titularEmVigor($dto, $ultimoDia);
        $this->exigirSemReescreverHistorico($titular, $ultimoDia, $turma->anoLectivo->data_fim->toImmutable());

        // O fim do antigo tem de estar gravado antes de o validador do novo correr, senão haveria falsa sobreposição.
        $titular->update(['data_fim' => $ultimoDia->toDateString(), 'motivo_encerramento' => $motivo]);

        return $this->atribuir->executar(new AtribuicaoDocenteDTO(
            professor_id: $dto->novo_professor_id,
            turma_id: $dto->turma_id,
            disciplina_id: $dto->disciplina_id,
            papel: PapelDocente::TITULAR,
            data_inicio: $ultimoDia->addDay()->toDateString(),
            data_fim: null,
        ));
    }

    /** A pauta tem de dizer quem leccionou em cada dia: nada do que já passou muda de professor. */
    private function exigirSemReescreverHistorico(AtribuicaoDocente $titular, CarbonImmutable $ultimoDia, CarbonImmutable $fimDoAno): void
    {
        $hoje = $this->relogio->hoje();

        if ($titular->estadoNaData($hoje) === 'terminada') {
            throw ValidationException::withMessages([
                'ultimo_dia_do_titular' => 'O titular nessa data já terminou a atribuição: uma atribuição terminada não se altera.',
            ]);
        }

        $ontem = $hoje->subDay();
        if ($ultimoDia->toDateString() < $ontem->toDateString()) {
            throw ValidationException::withMessages([
                'ultimo_dia_do_titular' => 'O último dia do titular não pode ser anterior a ' . $ontem->format('d/m/Y') . ': o novo titular começa, no mínimo, hoje.',
            ]);
        }

        if ($ultimoDia->toDateString() >= $fimDoAno->toDateString()) {
            throw ValidationException::withMessages([
                'ultimo_dia_do_titular' => 'O novo titular começaria depois do fim do ano lectivo (' . $fimDoAno->format('d/m/Y') . '): indique um último dia anterior.',
            ]);
        }
    }

    private function titularEmVigor(SubstituicaoTitularDTO $dto, CarbonImmutable $ultimoDia): AtribuicaoDocente
    {
        $titulares = AtribuicaoDocente::query()
            ->where('turma_id', $dto->turma_id)
            ->where('disciplina_id', $dto->disciplina_id)
            ->where('papel', PapelDocente::TITULAR->value);

        $titular = (clone $titulares)->emVigor($ultimoDia)->first();

        if ($titular !== null) {
            return $titular;
        }

        // Nunca criar uma vigência invertida: distingue «ainda nem tinha começado» de «não há titular».
        $posterior = (clone $titulares)->whereDate('data_inicio', '>', $ultimoDia->toDateString())->orderBy('data_inicio')->first();

        throw ValidationException::withMessages([
            'ultimo_dia_do_titular' => $posterior !== null
                ? 'O último dia do titular não pode ser anterior ao início da sua atribuição (' . $posterior->data_inicio->format('d/m/Y') . ').'
                : 'Não há titular em vigor nessa data para substituir.',
        ]);
    }
}
