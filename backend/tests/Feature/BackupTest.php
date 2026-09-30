<?php

namespace Tests\Feature;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_a_backup(): void
    {
        // Regresión: el generador de backup usaba sintaxis exclusiva de MySQL
        // (SHOW TABLES / SHOW CREATE TABLE) contra la base sqlite por defecto
        // de este proyecto, y tiraba un error en vez de generar el archivo.
        $admin = User::factory()->admin()->create();
        Sucursal::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->get('/api/backup/exportar');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/sql; charset=UTF-8');
        $sql = $response->getContent();
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('INSERT INTO `users`', $sql);
    }
}
