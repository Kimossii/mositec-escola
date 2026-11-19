<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rasto de cada aprovação e reabertura de uma pauta (só se acrescenta). A tabela das pautas guarda apenas o último
        // de cada um; este registo guarda todos, com quem fez, quando e o motivo.
        Schema::create('avaliacao_pauta_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('pauta_id');
            $table->unsignedTinyInteger('evento');
            $table->string('evento_descricao');
            $table->unsignedInteger('versao');
            $table->text('motivo')->nullable();
            $table->timestamp('ocorrido_em');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'pauta_id'])->references(['tenant_id', 'id'])->on('avaliacao_pautas')->restrictOnDelete();
            $table->index(['pauta_id', 'ocorrido_em']);
        });

        // Pautas que já existiam: fica registado, pelo menos, o último que se sabe.
        foreach (DB::table('avaliacao_pautas')->orderBy('id')->get() as $pauta) {
            $agora = now();
            if ($pauta->aprovada_em !== null) {
                DB::table('avaliacao_pauta_historico')->insert(['tenant_id' => $pauta->tenant_id, 'pauta_id' => $pauta->id, 'evento' => 0, 'evento_descricao' => 'Aprovada',
                    'versao' => max(1, (int) $pauta->versao), 'ocorrido_em' => $pauta->aprovada_em, 'criado_por' => $pauta->aprovada_por, 'created_at' => $agora, 'updated_at' => $agora]);
            }
            if ($pauta->reaberta_em !== null) {
                DB::table('avaliacao_pauta_historico')->insert(['tenant_id' => $pauta->tenant_id, 'pauta_id' => $pauta->id, 'evento' => 1, 'evento_descricao' => 'Reaberta',
                    'versao' => $pauta->versao, 'motivo' => $pauta->motivo_reabertura, 'ocorrido_em' => $pauta->reaberta_em, 'criado_por' => $pauta->reaberta_por, 'created_at' => $agora, 'updated_at' => $agora]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_pauta_historico');
    }
};
