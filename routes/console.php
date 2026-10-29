<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agendamentos de dados de escola usam sempre --todos (só tenants activos, um a um).
// `queue:work` e `schedule:work` são processos centrais, um só para todos os tenants: nunca levam --tenant.
Schedule::command('mosi:tenant:tokens:prune --todos')->daily();

// Propinas em falta das matrículas activas (só nas escolas com «gerar automaticamente» ligado nas Regras
// de Cobrança). Corre às 02:00 no fuso da aplicação/servidor (UTC), i.e. 03:00 em Luanda. Idempotente: apanha o que falhou na véspera.
Schedule::command('financeiro:gerar-propinas --todos --fila')->dailyAt('02:00')->withoutOverlapping();

// Com --fila cada escola é um job: em QUEUE_CONNECTION=sync corre já (como antes); com worker, em fila. Se a fila não tiver worker, `mosi:filas:estado` avisa.
// Multas por atraso (só nas escolas com multas activas). Corre DEPOIS da geração, às 02:30. Idempotente.
Schedule::command('financeiro:aplicar-multas --todos --fila')->dailyAt('02:30')->withoutOverlapping();

// Exportações em fila: os ficheiros duram 7 dias e os pedidos presos há 30 min falham.
Schedule::command('exportacoes:limpar')->everyTenMinutes()->withoutOverlapping();
