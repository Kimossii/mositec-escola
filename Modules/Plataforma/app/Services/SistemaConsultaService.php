<?php

namespace Modules\Plataforma\Services;

use Modules\Core\Support\EstadoDasFilas;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\Config\Config as ConfigDeBackups;
use Spatie\Backup\Helpers\Format;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatus;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Spatie\Health\ResultStores\ResultStore;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;

/**
 * Estado técnico do sistema para o painel: verificações de saúde (spatie/laravel-health), backups (spatie/laravel-backup)
 * e filas. São dados de infraestrutura, globais: nunca abrem contexto de escola. Só leitura.
 */
class SistemaConsultaService
{
    /** @return array{executado_em: ?string, verificacoes: list<array{nome: string, rotulo: string, estado: string, resumo: string, mensagem: ?string}>} */
    public function saude(): array
    {
        $resultados = app(ResultStore::class)->latestResults();

        return [
            'executado_em' => $resultados?->finishedAt?->format(DATE_ATOM),
            'verificacoes' => ($resultados?->storedCheckResults ?? collect())->map(fn (StoredCheckResult $r) => [
                'nome' => $r->name,
                'rotulo' => $r->label,
                'estado' => $r->status,
                'resumo' => $r->shortSummary,
                'mensagem' => $r->notificationMessage ?: null,
            ])->values()->all(),
        ];
    }

    /** @return list<array{nome: string, disco: string, acessivel: bool, saudavel: bool, quantidade: int, ultimo: ?string, espaco: string, problemas: list<string>}> */
    public function backups(): array
    {
        return BackupDestinationStatusFactory::createForMonitorConfig(app(ConfigDeBackups::class)->monitoredBackups)
            ->map(function (BackupDestinationStatus $estado) {
                $destino = $estado->backupDestination();
                $acessivel = $destino->isReachable();
                $ultimo = $acessivel ? $destino->newestBackup() : null;

                return [
                    'nome' => $destino->backupName(),
                    'disco' => $destino->diskName(),
                    'acessivel' => $acessivel,
                    'saudavel' => $estado->isHealthy(),
                    'quantidade' => $acessivel ? $destino->backups()->count() : 0,
                    'ultimo' => $ultimo instanceof Backup ? $ultimo->date()->toIso8601String() : null,
                    'espaco' => $acessivel ? Format::humanReadableSize($destino->usedStorage()) : '—',
                    'problemas' => $estado->failureMessages()->map(fn (array $falha) => (string) $falha['message'])->values()->all(),
                ];
            })->values()->all();
    }

    /** @return array{modo: string, filas: list<array{nome: string, em_espera: int, minutos: int, parada: bool}>, falhados: int} */
    public function filas(): array
    {
        return app(EstadoDasFilas::class)->obter();
    }
}
