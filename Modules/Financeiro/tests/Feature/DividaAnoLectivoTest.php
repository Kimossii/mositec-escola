<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarAnoLectivoAction;
use Modules\AnoLectivo\Actions\EliminarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Tests\TestCase;

/**
 * As competências de um plano ancoram-se no mês de data_inicio do ano lectivo; o ano lectivo
 * (que não importa o Financeiro) consulta os planos através do contrato DependenciasDoAnoLectivo.
 */
class DividaAnoLectivoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    private function dto(string $inicio, string $fim = '2027-07-31'): AnoLectivoDTO
    {
        return new AnoLectivoDTO(nome: '2026/2027', dataInicio: $inicio, dataFim: $fim, estado: EstadoAnoLectivo::ATIVO);
    }

    public function test_mudar_o_mes_do_inicio_com_planos_e_bloqueado(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Propina', ['estado' => Estado::INATIVO->value]);

        try {
            (new AtualizarAnoLectivoAction())->atualizar($ano, $this->dto('2026-10-01'));
            $this->fail('Devia bloquear a alteração do mês de início.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('planos de propina', $e->errors()['data_inicio'][0]);
        }

        $this->assertSame('2026-09-01', $ano->fresh()->data_inicio->toDateString());
    }

    public function test_mudar_o_ano_do_inicio_com_planos_e_bloqueado(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Propina');

        $this->expectException(ValidationException::class);
        (new AtualizarAnoLectivoAction())->atualizar($ano, $this->dto('2025-09-01', '2026-07-31'));
    }

    public function test_mudar_so_o_dia_dentro_do_mesmo_mes_e_permitido(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Propina');

        (new AtualizarAnoLectivoAction())->atualizar($ano, $this->dto('2026-09-15', '2027-08-15'));

        $ano->refresh();
        $this->assertSame('2026-09-15', $ano->data_inicio->toDateString());
        $this->assertSame('2027-08-15', $ano->data_fim->toDateString());
    }

    public function test_sem_planos_pode_mudar_o_mes_do_inicio(): void
    {
        $ano = $this->anoLectivo();

        (new AtualizarAnoLectivoAction())->atualizar($ano, $this->dto('2026-10-01'));

        $this->assertSame('2026-10-01', $ano->fresh()->data_inicio->toDateString());
    }

    public function test_planos_de_outro_ano_lectivo_nao_bloqueiam(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $b->update(['estado' => EstadoAnoLectivo::PLANEADO]);
        $this->plano($b, 'Propina B');

        (new AtualizarAnoLectivoAction())->atualizar($a, $this->dto('2026-10-01'));

        $this->assertSame('2026-10-01', $a->fresh()->data_inicio->toDateString());
    }

    public function test_planos_de_outro_tenant_sao_irrelevantes(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        // Só o outro tenant tem o ano lectivo (mesmo id lógico) com plano: se contasse, bloquearia.
        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Do Outro'));
        $ano = $this->anoLectivo();
        // Passa o plano do outro tenant para o MESMO ano_lectivo_id: só o isolamento o torna irrelevante.
        PlanoPropina::withoutGlobalScopes()->update(['ano_lectivo_id' => $ano->id]);
        $this->assertSame(0, PlanoPropina::query()->count());

        (new AtualizarAnoLectivoAction())->atualizar($ano, $this->dto('2026-10-01'));
        (new EliminarAnoLectivoAction())->executar($ano);

        $this->assertSoftDeleted('ano_lectivos', ['id' => $ano->id]);
    }

    public function test_data_inicio_inalterada_com_outros_campos_editados_e_permitida(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Propina');

        (new AtualizarAnoLectivoAction())->atualizar($ano, new AnoLectivoDTO(
            nome: 'Renomeado', dataInicio: '2026-09-01', dataFim: '2027-08-31', estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->assertSame('Renomeado', $ano->fresh()->nome);
    }

    public function test_data_inicio_iso_com_fuso_compara_o_dia_civil_sem_falsos_bloqueios(): void
    {
        $ano = $this->anoLectivo('2026/2027', '2026-09-15');
        $this->plano($ano, 'Propina');

        // Mesmo mês civil (data tal como escrita, sem conversão de fuso): permitido.
        (new AtualizarAnoLectivoAction())->atualizar($ano, $this->dto('2026-09-14T23:00:00Z'));
        $this->assertSame('2026-09', $ano->fresh()->data_inicio->format('Y-m'));

        // Mês civil diferente: bloqueado.
        $this->expectException(ValidationException::class);
        (new AtualizarAnoLectivoAction())->atualizar($ano->fresh(), $this->dto('2026-10-01T00:30:00Z'));
    }

    public function test_eliminar_ano_lectivo_com_planos_e_bloqueado(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Propina');

        try {
            (new EliminarAnoLectivoAction())->executar($ano);
            $this->fail('Devia bloquear a eliminação.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('planos de propina', $e->errors()['ano_lectivo'][0]);
        }

        $this->assertNotSoftDeleted('ano_lectivos', ['id' => $ano->id]);
    }

    public function test_eliminar_ano_lectivo_sem_planos_e_permitido(): void
    {
        $ano = $this->anoLectivo();

        (new EliminarAnoLectivoAction())->executar($ano);

        $this->assertSoftDeleted('ano_lectivos', ['id' => $ano->id]);
    }
}
