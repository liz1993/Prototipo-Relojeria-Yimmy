<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers multi-sucursal data isolation and the admin-only permission gate.
 * Includes a regression test for the cross-branch bug found in
 * ReparacionController@update (empleados could change the estado of a
 * reparación belonging to another sucursal by guessing its id).
 */
class SucursalScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_empleado_only_sees_inventario_from_own_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();

        Inventario::factory()->for($centro)->create(['descripcion' => 'Reloj Centro']);
        Inventario::factory()->for($norte)->create(['descripcion' => 'Reloj Norte']);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/inventario');

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.descripcion', 'Reloj Centro');
    }

    public function test_empleado_only_sees_ventas_from_own_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();

        Venta::factory()->for($centro)->create();
        Venta::factory()->for($norte)->create();

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/ventas');

        $response->assertOk()->assertJsonCount(1);
    }

    public function test_empleado_only_sees_reparaciones_from_own_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();

        Reparacion::factory()->for($centro)->create();
        Reparacion::factory()->for($norte)->create();

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/reparaciones');

        $response->assertOk()->assertJsonCount(1);
    }

    public function test_admin_sees_data_from_every_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        Inventario::factory()->for($centro)->create();
        Inventario::factory()->for($norte)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/inventario');

        $response->assertOk()->assertJsonCount(2);
    }

    public function test_admin_can_filter_by_sucursal_id(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        Inventario::factory()->for($centro)->create();
        Inventario::factory()->for($norte)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/inventario?sucursal_id='.$centro->id);

        $response->assertOk()->assertJsonCount(1);
    }

    public function test_empleado_cannot_change_estado_of_reparacion_in_another_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleadoCentro = User::factory()->empleado($centro)->create();
        $reparacionNorte = Reparacion::factory()->for($norte)->create(['estado' => 'pendiente']);

        $response = $this->actingAs($empleadoCentro, 'sanctum')
            ->putJson("/api/reparaciones/{$reparacionNorte->id}", ['estado' => 'entregado']);

        $response->assertStatus(404);
        $this->assertSame('pendiente', $reparacionNorte->fresh()->estado);
    }

    public function test_empleado_can_change_estado_of_reparacion_in_own_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();
        $reparacion = Reparacion::factory()->for($centro)->create(['estado' => 'pendiente']);

        $response = $this->actingAs($empleado, 'sanctum')
            ->putJson("/api/reparaciones/{$reparacion->id}", ['estado' => 'en_proceso']);

        $response->assertOk()->assertJsonPath('estado', 'en_proceso');
    }

    public function test_empleado_cannot_edit_client_or_amount_fields_of_a_reparacion(): void
    {
        $centro = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();
        $reparacion = Reparacion::factory()->for($centro)->create([
            'cliente' => 'Cliente Original',
            'valor_total' => 100,
        ]);

        $response = $this->actingAs($empleado, 'sanctum')->putJson("/api/reparaciones/{$reparacion->id}", [
            'estado' => 'en_proceso',
            'cliente' => 'Cliente Hackeado',
            'valor_total' => 999999,
        ]);

        $response->assertOk();
        $reparacion->refresh();
        $this->assertSame('Cliente Original', $reparacion->cliente);
        $this->assertSame('100.00', (string) $reparacion->valor_total);
    }

    public function test_empleado_cannot_create_inventario_item(): void
    {
        $centro = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $centro->id,
            'codigo' => 'X-1',
            'descripcion' => 'Intento no autorizado',
        ]);

        $response->assertStatus(403);
    }

    public function test_empleado_cannot_delete_ventas_or_reparaciones(): void
    {
        $centro = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();
        $venta = Venta::factory()->for($centro)->create();
        $reparacion = Reparacion::factory()->for($centro)->create();

        $this->actingAs($empleado, 'sanctum')->deleteJson("/api/ventas/{$venta->id}")->assertStatus(403);
        $this->actingAs($empleado, 'sanctum')->deleteJson("/api/reparaciones/{$reparacion->id}")->assertStatus(403);
    }

    public function test_empleado_cannot_access_admin_only_endpoints(): void
    {
        $centro = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();

        $this->actingAs($empleado, 'sanctum')->getJson('/api/usuarios')->assertStatus(403);
        $this->actingAs($empleado, 'sanctum')->getJson('/api/reportes')->assertStatus(403);
        $this->actingAs($empleado, 'sanctum')->getJson('/api/cierres')->assertStatus(403);
        $this->actingAs($empleado, 'sanctum')->getJson('/api/papelera')->assertStatus(403);
        $this->actingAs($empleado, 'sanctum')->getJson('/api/backup/exportar')->assertStatus(403);
        $this->actingAs($empleado, 'sanctum')->postJson('/api/sucursales', ['nombre' => 'Nueva'])->assertStatus(403);
    }
}
