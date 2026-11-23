<?php

namespace Modules\Core\Tests\Feature;

use App\Services\DashboardService;
use Modules\Avaliacao\Services\BoletimDocumentoService;
use Modules\Core\Contracts\ConsultaChamadasPendentes;
use Modules\Core\Contracts\ConsultaFaltasDoAluno;
use Modules\Core\Support\SemFrequencia\FaltasDoAlunoVazias;
use Modules\Core\Support\SemFrequencia\SemChamadasPendentes;
use Tests\TestCase;

/** Sem o módulo Frequencia (desligado em `modules_statuses.json`) o dashboard e o boletim continuam a funcionar, sem faltas. */
class FrequenciaDesligadaTest extends TestCase
{
    public function test_a_implementacao_de_recurso_das_faltas_devolve_zeros_sem_chamadas(): void
    {
        $r = (new FaltasDoAlunoVazias())->resumoDaMatricula(123);

        $this->assertSame(['aulas', 0, 0, 0, false, [], []], [$r['unidade'], $r['total'], $r['justificadas'], $r['injustificadas'], $r['com_chamadas'], $r['por_disciplina'], $r['por_periodo']]);
    }

    public function test_a_implementacao_de_recurso_das_pendentes_devolve_zero(): void
    {
        $this->assertSame(0, (new SemChamadasPendentes())->doUtilizador(1));
        $this->assertSame(0, (new SemChamadasPendentes())->daEscola());
    }

    public function test_dashboard_e_boletim_resolvem_com_as_implementacoes_de_recurso(): void
    {
        $this->app->bind(ConsultaFaltasDoAluno::class, FaltasDoAlunoVazias::class);
        $this->app->bind(ConsultaChamadasPendentes::class, SemChamadasPendentes::class);

        $this->assertInstanceOf(DashboardService::class, $this->app->make(DashboardService::class));
        $this->assertInstanceOf(BoletimDocumentoService::class, $this->app->make(BoletimDocumentoService::class));
    }

    public function test_o_core_regista_o_recurso_mas_o_modulo_activo_prevalece(): void
    {
        $this->assertInstanceOf(\Modules\Frequencia\Services\FaltasDoAlunoService::class, $this->app->make(ConsultaFaltasDoAluno::class));
        $this->assertInstanceOf(\Modules\Frequencia\Services\ChamadasPorFazerService::class, $this->app->make(ConsultaChamadasPendentes::class));
    }
}
