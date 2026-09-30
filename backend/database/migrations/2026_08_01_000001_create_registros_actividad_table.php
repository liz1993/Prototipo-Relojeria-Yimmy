<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de auditoría: a diferencia del resto de la app (que solo
     * refleja el estado actual), esto registra CADA acción (crear/modificar/
     * eliminar) tal cual pasó, para que el dueño pueda ver en "Actividad" si
     * una empleada corrigió o borró una venta después de registrarla -- algo
     * que el feed de Actividad (reconstruido desde las filas vivas) no podía
     * mostrar antes, porque una edición no deja rastro y un borrado (soft
     * delete) simplemente desaparece de las consultas normales.
     */
    public function up(): void
    {
        Schema::create('registros_actividad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sucursal_id')->nullable()->constrained('sucursales')->nullOnDelete();
            $table->string('entidad'); // 'venta' por ahora
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->enum('accion', ['creado', 'modificado', 'eliminado']);
            // Para que el feed de Actividad pueda filtrar por rubro sin mezclar
            // Relojería y Joyería (ver Sucursal::permiteModulo).
            $table->enum('modulo', ['relojeria', 'joyeria'])->default('relojeria');
            $table->text('descripcion');
            $table->decimal('monto', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros_actividad');
    }
};
