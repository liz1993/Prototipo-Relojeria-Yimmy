<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Default true: las cuentas creadas por un admin (o por seeders/factories)
     * no necesitan revisión y quedan activas de una. Solo el autoregistro
     * público (AuthController::register) las crea en false explícitamente.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('aprobado')->default(true)->after('tipo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('aprobado');
        });
    }
};
