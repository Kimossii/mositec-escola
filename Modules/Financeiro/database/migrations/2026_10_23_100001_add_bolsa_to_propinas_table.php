<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
    }
};
