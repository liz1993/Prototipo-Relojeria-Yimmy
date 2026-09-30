<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El unique(['sucursal_id', 'codigo']) no distingue productos soft-deleted,
     * así que un código borrado (a la papelera) quedaba bloqueado para siempre
     * en esa sucursal. Se baja a un índice normal (para performance de
     * búsqueda/validación) y la unicidad real de productos activos queda a
     * cargo de la regla Rule::unique(...)->whereNull('deleted_at') en
     * InventarioController.
     */
    public function up(): void
    {
        Schema::table('inventario', function (Blueprint $table) {
            $table->dropUnique(['sucursal_id', 'codigo']);
            $table->index(['sucursal_id', 'codigo']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventario', function (Blueprint $table) {
            $table->dropIndex(['sucursal_id', 'codigo']);
            $table->unique(['sucursal_id', 'codigo']);
        });
    }
};
