<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Passa os BI já gravados à forma normalizada (sem espaços, maiúsculas) que `DadosPessoa` passou a gravar. Se a forma
 * normalizada já pertence a outra pessoa da mesma escola (duplicado escondido pela formatação), o registo fica como
 * está e vai para o log: a secretaria decide qual dos dois é o correcto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existentes = [];
        foreach (DB::table('dados_pessoas')->select('tenant_id', 'numero_identificacao')->cursor() as $linha) {
            $existentes[$linha->tenant_id][$linha->numero_identificacao] = true;
        }

        $conflitos = [];

        foreach (DB::table('dados_pessoas')->select('id', 'tenant_id', 'numero_identificacao')->orderBy('id')->cursor() as $linha) {
            $normalizado = mb_strtoupper(preg_replace('/\s+/u', '', $linha->numero_identificacao));

            if ($normalizado === $linha->numero_identificacao) {
                continue;
            }

            if (isset($existentes[$linha->tenant_id][$normalizado])) {
                $conflitos[] = "pessoa #{$linha->id} (tenant {$linha->tenant_id}): «{$linha->numero_identificacao}» colide com «{$normalizado}»";

                continue;
            }

            DB::table('dados_pessoas')->where('id', $linha->id)->update(['numero_identificacao' => $normalizado]);
            unset($existentes[$linha->tenant_id][$linha->numero_identificacao]);
            $existentes[$linha->tenant_id][$normalizado] = true;
        }

        if ($conflitos !== []) {
            Log::warning('BI duplicados pela formatação, por corrigir à mão: '.implode('; ', $conflitos));
        }
    }

    public function down(): void
    {
        // Irreversível: a formatação original não se guarda.
    }
};
