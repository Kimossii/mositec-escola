<?php

namespace Modules\Autenticacao\Service;

use Carbon\CarbonImmutable;
use Modules\Autenticacao\Models\SessaoDeUtilizador;
use Modules\Autenticacao\Support\DescricaoDoDispositivo;
use Modules\Usuario\Models\User;

class SessoesDoUtilizadorService
{
    /** Referência opaca de uma sessão (HMAC do identificador): permite terminá-la sem nunca expor o identificador real. */
    public static function referencia(string $idSessao): string
    {
        return hash_hmac('sha256', $idSessao, (string) config('app.key'));
    }

    /**
     * Sessões do utilizador, a actual primeiro e depois as mais recentes. Nunca expõe o identificador da sessão (só uma referência opaca).
     *
     * @return list<array{referencia: string, actual: bool, navegador: string, sistema: string, tipo: string, ip: string|null, ultima_actividade: string}>
     */
    public function listar(User $user, string $idSessaoActual): array
    {
        return SessaoDeUtilizador::where('user_id', $user->id)->orderByDesc('last_activity')->get()
            ->map(fn (SessaoDeUtilizador $s) => [
                'referencia' => self::referencia($s->id),
                'actual' => $s->id === $idSessaoActual,
                ...DescricaoDoDispositivo::de($s->user_agent),
                'ip' => $s->ip_address,
                'ultima_actividade' => CarbonImmutable::createFromTimestamp((int) $s->last_activity)->toIso8601String(),
            ])
            ->sortByDesc('actual')->values()->all();
    }
}
