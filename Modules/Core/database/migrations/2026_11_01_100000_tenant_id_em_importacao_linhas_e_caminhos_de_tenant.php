<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importacao_linhas', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->restrictOnDelete();
        });

        DB::statement('UPDATE importacao_linhas SET tenant_id = (SELECT tenant_id FROM importacoes WHERE importacoes.id = importacao_linhas.importacao_id)');

        Schema::table('importacao_linhas', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable(false)->change();
        });

        // Ficheiros guardados antes da convenção `tenants/{id}/...`: passam para debaixo do prefixo do tenant.
        $disco = Storage::disk('local');

        foreach (['importacoes', 'pedidos_exportacao'] as $tabela) {
            foreach (DB::table($tabela)->whereNotNull('caminho')->where('caminho', 'not like', 'tenants/%')->get(['id', 'tenant_id', 'caminho']) as $linha) {
                // `exportacoes/{tenant}/x.pdf` -> `tenants/{tenant}/exportacoes/x.pdf`; `importacoes/{tenant}/x.csv` idem.
                $novo = 'tenants/'.$linha->tenant_id.'/'.preg_replace('#^([^/]+)/'.$linha->tenant_id.'/#', '$1/', $linha->caminho);

                if ($disco->exists($linha->caminho) && $disco->move($linha->caminho, $novo)) {
                    DB::table($tabela)->where('id', $linha->id)->update(['caminho' => $novo]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('importacao_linhas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
