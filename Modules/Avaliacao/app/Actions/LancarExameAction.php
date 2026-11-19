<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarExameDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\AcessoAMiniPautaService;
use Modules\Avaliacao\Services\InscricaoDaAvaliacaoService;
use Modules\Core\Traits\LogService;
use Modules\Turma\Models\Turma;

/**
 * Lança a nota do exame próprio (NE) de um aluno. O exame de cada turma e disciplina cria-se na primeira nota, no 3.º
 * trimestre, e a nota segue as regras de quem pode e de prazo das restantes notas. Se a nota for recusada, o exame criado
 * desfaz-se com ela (mesma transacção).
 */
class LancarExameAction
{
    use LogService;

    public function __construct(private CriarInstrumentoAction $criar, private LancarNotaAction $lancar, private AcessoAMiniPautaService $acesso, private InscricaoDaAvaliacaoService $inscricoes)
    {
    }

    public function executar(LancarExameDTO $dto): AvaliacaoNota
    {
        return $this->comLog('Lançar nota de exame', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => DB::transaction(function () use ($dto) {
            $this->acesso->exigirDoUtilizadorActual($dto->turma_id, $dto->disciplina_id);
            $turma = Turma::query()->findOrFail($dto->turma_id);
            $inscricao = $this->inscricoes->exigir($dto->inscricao_disciplina_id, $turma->id, $dto->disciplina_id);

            $exame = AvaliacaoInstrumento::query()->where('turma_id', $turma->id)->where('disciplina_id', $dto->disciplina_id)->where('tipo', TipoInstrumento::EXAME->value)->first()
                ?? $this->criar->executar(new InstrumentoDTO($turma->id, $dto->disciplina_id, $this->inscricoes->terceiroTrimestre($turma)->id, TipoInstrumento::EXAME, 'Exame'));

            return $this->lancar->executar(new LancarNotaDTO($exame->id, $dto->inscricao_disciplina_id, $dto->valor, $dto->motivo));
        }));
    }
}
