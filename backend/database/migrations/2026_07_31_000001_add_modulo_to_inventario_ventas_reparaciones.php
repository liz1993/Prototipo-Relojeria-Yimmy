<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separa el negocio en dos rubros (Relojería / Joyería) que no se mezclan entre
     * sí: mismo default 'relojeria' en las tres tablas porque todo lo que existe hoy
     * (seeders, datos ya cargados) es del negocio de relojes.
     */
    public function up(): void
    {
        Schema::table('inventario', function (Blueprint $table) {
            $table->enum('modulo', ['relojeria', 'joyeria'])->default('relojeria')->after('sucursal_id');
        });
        Schema::table('ventas', function (Blueprint $table) {
            $table->enum('modulo', ['relojeria', 'joyeria'])->default('relojeria')->after('sucursal_id');
        });
        Schema::table('reparaciones', function (Blueprint $table) {
            $table->enum('modulo', ['relojeria', 'joyeria'])->default('relojeria')->after('sucursal_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventario', function (Blueprint $table) {
            $table->dropColumn('modulo');
        });
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropColumn('modulo');
        });
        Schema::table('reparaciones', function (Blueprint $table) {
            $table->dropColumn('modulo');
        });
    }
};
