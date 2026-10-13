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
Schedule::command('financeiro:gerar-propinas --todos')->dailyAt('02:00')->withoutOverlapping();
