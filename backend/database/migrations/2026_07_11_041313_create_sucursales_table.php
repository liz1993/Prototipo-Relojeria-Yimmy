<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('direccion')->nullable();
            $table->string('telefono')->nullable();
            $table->timestamps();
        });

        $ahora = now();
        DB::table('sucursales')->insert([
            ['nombre' => 'Sucursal 1', 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Sucursal 2', 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Sucursal 3', 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Sucursal 4', 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sucursales');
    }
};
