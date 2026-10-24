<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bolsa de estudo: cobre uma percentagem das propinas de um aluno num ano lectivo, entre duas datas.
        Schema::create('bolsas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->string('nome', 150); // ex.: Bolsa de mérito
            $table->unsignedSmallInteger('percentagem'); // pontos-base: 5000 = 50%, 10000 = 100%
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->text('motivo');
            $table->unsignedTinyInteger('estado')->default(1); // 1 activa, 2 terminada
            $table->string('estado_descricao')->default('Activa');
            $table->text('motivo_termino')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'aluno_id', 'ano_lectivo_id']);
        });

        Schema::table('propinas', function (Blueprint $table) {
            $table->unsignedBigInteger('bolsa_id')->nullable()->after('plano_propina_id');
            $table->unsignedBigInteger('valor_bolsa')->default(0)->after('valor_original'); // quanto a bolsa abateu ao valor do plano
        });

        // A chave estrangeira só em PostgreSQL: em SQLite acrescentá-la reconstrói a tabela e perde os índices únicos parciais.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE propinas ADD CONSTRAINT propinas_bolsa_id_foreign FOREIGN KEY (bolsa_id) REFERENCES bolsas (id) ON DELETE RESTRICT');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE propinas DROP CONSTRAINT IF EXISTS propinas_bolsa_id_foreign');
        }

        Schema::table('propinas', function (Blueprint $table) {
            $table->dropColumn(['bolsa_id', 'valor_bolsa']);
        });
        Schema::dropIfExists('bolsas');
    }
};
