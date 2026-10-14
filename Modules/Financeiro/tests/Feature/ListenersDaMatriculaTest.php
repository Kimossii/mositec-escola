<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Financeiro\Listeners\CancelarPropinasAoTerminarMatricula;
use Modules\Financeiro\Listeners\GerarPropinasAoActivarMatricula;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Tests\TestCase;

/**
 * D1: o Financeiro escuta o evento da Matrícula com registo explícito (FinanceiroServiceProvider::boot), uma só vez.
 * O cancelamento é síncrono (corre na transacção da matrícula, Q5). NUNCA usar Event::fake sem lista de eventos em testes
 * que dependam destes listeners: desliga-os.
 */
class ListenersDaMatriculaTest extends TestCase
{
    // O TestCase cria o tenant de teste: sem RefreshDatabase, o 2.º teste colide numa base persistente (PostgreSQL).
    use RefreshDatabase;

    /** @return list<string> */
    private function listenersRegistados(): array
    {
        return array_values(array_filter(
            Event::getRawListeners()[MatriculaEstadoAlterado::class] ?? [],
            'is_string',
        ));
    }

    public function test_o_cancelamento_esta_registado_uma_so_vez_e_e_sincrono(): void
    {
        $this->assertSame(1, count(array_keys($this->listenersRegistados(), CancelarPropinasAoTerminarMatricula::class, true)));
        $this->assertFalse(is_subclass_of(CancelarPropinasAoTerminarMatricula::class, ShouldHandleEventsAfterCommit::class));
    }

    public function test_a_geracao_esta_registada_uma_so_vez_e_corre_depois_do_commit(): void
    {
        $this->assertSame(1, count(array_keys($this->listenersRegistados(), GerarPropinasAoActivarMatricula::class, true)));
        $this->assertTrue(is_subclass_of(GerarPropinasAoActivarMatricula::class, ShouldHandleEventsAfterCommit::class));
    }
}
