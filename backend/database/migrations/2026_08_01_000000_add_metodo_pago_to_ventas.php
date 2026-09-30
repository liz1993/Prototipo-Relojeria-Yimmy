<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Para que el cierre del día pueda separar cuánto se cobró en efectivo
     * de cuánto en Nequi (el resto queda implícito: total - nequi).
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('metodo_pago', ['efectivo', 'nequi'])->default('efectivo')->after('tipo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('metodo_pago');
        });
    }
};
