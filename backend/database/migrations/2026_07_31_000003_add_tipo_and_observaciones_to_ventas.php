<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "tipo" distingue una venta de producto de un arreglo rápido registrado
     * acá mismo (ej. "cambio de pila", "ajuste de correa") que no amerita
     * abrir una Reparación completa (con cliente, abono, foto, estado). Se
     * suma "observaciones" libre para cualquiera de los dos casos.
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('tipo', ['venta', 'arreglo'])->default('venta')->after('modulo');
            $table->text('observaciones')->nullable()->after('fecha');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'observaciones']);
        });
    }
};
