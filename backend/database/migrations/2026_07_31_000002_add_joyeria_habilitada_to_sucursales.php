<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No todas las sucursales manejan joyería -- este flag decide si el módulo
     * Joyería aparece habilitado para esa sede. Default false: hoy ninguna
     * sucursal existente vende joyas, hay que prenderlo a mano por sede.
     */
    public function up(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->boolean('joyeria_habilitada')->default(false)->after('telefono');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sucursales', function (Blueprint $table) {
            $table->dropColumn('joyeria_habilitada');
        });
    }
};
