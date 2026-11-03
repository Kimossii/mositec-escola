<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\EstadoPauta;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Models\AvaliacaoPautaLinha;
use Modules\Avaliacao\Models\AvaliacaoPautaLinhaQualitativa;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Core\Traits\LogService;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Turma\Models\Turma;

/**
 * Passa a pauta final de provisória (ou reaberta) a definitiva e grava o instantâneo: o que está impresso, mais a
 * decisão de cada aluno. Só com `nota.aprovar` e só quando nada impede (sem pendentes, sem exame por fazer).
 * A aprovação é que formaliza o Transita/Não transita; antes disso é só um resultado provisório.
 */
class AprovarPautaAction
{
    use LogService;

    public function __construct(private PautaFinalService $pautas, private RelogioDoTenant $relogio)
    {
    }

    public function executar(int $turmaId): AvaliacaoPauta
    {
        return $this->comLog('Aprovar pauta final', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($turmaId) {
            if (! Gate::allows('nota.aprovar')) {
                throw new AuthorizationException('Sem permissão para aprovar pautas.');
            }

            Turma::query()->whereKey($turmaId)->lockForUpdate()->firstOrFail();
            $existente = AvaliacaoPauta::query()->where('turma_id', $turmaId)->lockForUpdate()->first();

            if ($existente?->estado === EstadoPauta::DEFINITIVA) {
                throw ValidationException::withMessages(['pauta' => 'Esta pauta já é definitiva.']);
            }

            $viva = $this->pautas->montar($turmaId);
            if (! $viva['pode_aprovar']) {
                throw ValidationException::withMessages(['pauta' => $viva['bloqueios'][0] ?? 'A pauta não pode ser aprovada.']);
            }

            $versao = ($existente?->versao ?? 0) + 1;
            $pauta = $existente ?? new AvaliacaoPauta(['turma_id' => $turmaId]);
            $pauta->fill(['estado' => EstadoPauta::DEFINITIVA, 'versao' => $versao, 'casas_decimais' => $viva['regra']['casas_decimais'] ?? 2, 'aprovada_por' => auth()->id(), 'aprovada_em' => $this->relogio->agora()])->save();

            foreach ($viva['alunos'] as $aluno) {
                foreach ($aluno['celulas'] as $celula) {
                    if ($viva['tipo'] === 'qualitativa') {
                        AvaliacaoPautaLinhaQualitativa::create(['pauta_id' => $pauta->id, 'versao' => $versao, 'inscricao_disciplina_id' => $celula['inscricao_id'], ...$celula['niveis']]);

                        continue;
                    }

                    AvaliacaoPautaLinha::create([
                        'pauta_id' => $pauta->id, 'versao' => $versao, 'inscricao_disciplina_id' => $celula['inscricao_id'],
                        'mt1' => $celula['mt1'], 'mt2' => $celula['mt2'], 'mt3' => $celula['mt3'],
                        'mfd_exacta' => $celula['mfd_exacta'], 'mfd' => $celula['mfd'], 'aprovado' => $celula['aprovado'],
                    ]);
                }

                AvaliacaoDecisao::create([
                    'pauta_id' => $pauta->id, 'versao' => $versao, 'matricula_id' => $aluno['matricula_id'],
                    'decisao' => Decisao::from($aluno['decisao']['valor']),
                ]);
            }

            return $pauta;
        }));
    }
}
