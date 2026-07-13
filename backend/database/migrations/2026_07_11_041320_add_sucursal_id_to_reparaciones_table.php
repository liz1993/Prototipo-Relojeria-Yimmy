<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reparaciones', function (Blueprint $table) {
            $table->foreignId('sucursal_id')->nullable()->after('id')->constrained('sucursales')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reparaciones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sucursal_id');
        });
    }
};
