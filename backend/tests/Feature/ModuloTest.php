<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuloTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_enable_joyeria_for_a_sucursal(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => false]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/sucursales/{$sucursal->id}", [
            'nombre' => $sucursal->nombre,
            'joyeria_habilitada' => true,
        ]);

        $response->assertOk()->assertJsonPath('joyeria_habilitada', true);
        $this->assertTrue($sucursal->fresh()->joyeria_habilitada);
    }

    public function test_unchecking_joyeria_habilitada_turns_it_off(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => true]);
        $admin = User::factory()->admin()->create();

        // No se manda "joyeria_habilitada" -- simula un checkbox desmarcado en el form.
        $this->actingAs($admin, 'sanctum')->putJson("/api/sucursales/{$sucursal->id}", [
            'nombre' => $sucursal->nombre,
        ])->assertOk();

        $this->assertFalse($sucursal->fresh()->joyeria_habilitada);
    }

    public function test_cannot_create_joyeria_inventario_in_sucursal_without_it_habilitada(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => false]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'modulo' => 'joyeria',
            'codigo' => 'AN-001',
            'descripcion' => 'Anillo de oro',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('modulo');
    }

    public function test_can_create_joyeria_inventario_when_sucursal_has_it_habilitada(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => true]);
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'modulo' => 'joyeria',
            'codigo' => 'AN-001',
            'descripcion' => 'Anillo de oro',
        ]);

        $response->assertCreated()->assertJsonPath('modulo', 'joyeria');
    }

    public function test_inventario_created_without_modulo_defaults_to_relojeria(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'codigo' => 'REL-100',
            'descripcion' => 'Reloj clásico',
        ]);

        $response->assertCreated()->assertJsonPath('modulo', 'relojeria');
    }

    public function test_index_only_returns_items_from_the_requested_modulo(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => true]);
        $admin = User::factory()->admin()->create();
        Inventario::factory()->for($sucursal)->create(['modulo' => 'relojeria', 'descripcion' => 'Reloj']);
        Inventario::factory()->for($sucursal)->create(['modulo' => 'joyeria', 'descripcion' => 'Anillo']);

        $reloj = $this->actingAs($admin, 'sanctum')->getJson('/api/inventario');
        $joyas = $this->actingAs($admin, 'sanctum')->getJson('/api/inventario?modulo=joyeria');

        $reloj->assertOk()->assertJsonCount(1)->assertJsonPath('0.descripcion', 'Reloj');
        $joyas->assertOk()->assertJsonCount(1)->assertJsonPath('0.descripcion', 'Anillo');
    }

    public function test_venta_linked_to_inventario_inherits_its_modulo_ignoring_what_was_sent(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => true]);
        $empleado = User::factory()->empleado($sucursal)->create();
        $anillo = Inventario::factory()->for($sucursal)->create(['modulo' => 'joyeria', 'cantidad' => 5]);

        // Manda "modulo=relojeria" a propósito -- debe ganar el módulo real del producto (joyeria).
        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Anillo de oro',
            'inventario_id' => $anillo->id,
            'modulo' => 'relojeria',
        ]);

        $response->assertCreated()->assertJsonPath('modulo', 'joyeria');
    }

    public function test_venta_libre_defaults_to_relojeria_when_modulo_not_sent(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Pila de repuesto',
            'valor' => 5,
        ]);

        $response->assertCreated()->assertJsonPath('modulo', 'relojeria');
    }

    public function test_venta_libre_can_be_tagged_joyeria_when_sucursal_allows_it(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => true]);
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Ajuste de anillo (servicio)',
            'valor' => 15,
            'modulo' => 'joyeria',
        ]);

        $response->assertCreated()->assertJsonPath('modulo', 'joyeria');
    }

    public function test_venta_libre_joyeria_rejected_when_sucursal_does_not_allow_it(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => false]);
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Ajuste de anillo (servicio)',
            'valor' => 15,
            'modulo' => 'joyeria',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('modulo');
    }

    public function test_reparacion_joyeria_rejected_when_sucursal_does_not_allow_it(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => false]);
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/reparaciones', [
            'cliente' => 'Cliente Prueba',
            'modulo' => 'joyeria',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('modulo');
    }

    public function test_ventas_index_scoped_by_modulo_never_mixes_rubros(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => true]);
        $empleado = User::factory()->empleado($sucursal)->create();
        Venta::factory()->create(['sucursal_id' => $sucursal->id, 'modulo' => 'relojeria']);
        Venta::factory()->create(['sucursal_id' => $sucursal->id, 'modulo' => 'joyeria']);

        $this->actingAs($empleado, 'sanctum')->getJson('/api/ventas')->assertJsonCount(1);
        $this->actingAs($empleado, 'sanctum')->getJson('/api/ventas?modulo=joyeria')->assertJsonCount(1);
    }

    public function test_reportes_scoped_by_modulo_never_mixes_rubros(): void
    {
        $sucursal = Sucursal::factory()->create(['joyeria_habilitada' => true]);
        $admin = User::factory()->admin()->create();
        Venta::factory()->create(['sucursal_id' => $sucursal->id, 'modulo' => 'relojeria', 'valor' => 100]);
        Venta::factory()->create(['sucursal_id' => $sucursal->id, 'modulo' => 'joyeria', 'valor' => 900]);

        $reporteRelojeria = $this->actingAs($admin, 'sanctum')->getJson('/api/reportes');
        $reporteJoyeria = $this->actingAs($admin, 'sanctum')->getJson('/api/reportes?modulo=joyeria');

        $reporteRelojeria->assertOk()->assertJsonPath('ventas.total', 100);
        $reporteJoyeria->assertOk()->assertJsonPath('ventas.total', 900);
    }
}
