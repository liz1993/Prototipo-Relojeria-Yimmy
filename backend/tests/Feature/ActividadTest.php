<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Movimiento;
use App\Models\RegistroActividad;
use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActividadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_actividad_from_every_sucursal_merged_and_ordered(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $itemCentro = Inventario::factory()->for($centro)->create();

        $venta = Venta::factory()->create(['sucursal_id' => $centro->id, 'created_at' => now()->subMinutes(3)]);
        // El evento de auditoría de la venta se crea en el momento real (ahora),
        // no hereda el created_at forzado de la venta -- se retrasa a mano acá
        // para poder controlar el orden del feed en este test.
        RegistroActividad::where('entidad_id', $venta->id)->update(['created_at' => now()->subMinutes(3)]);
        Reparacion::factory()->create(['sucursal_id' => $norte->id, 'created_at' => now()->subMinutes(2)]);
        Movimiento::factory()->for($itemCentro, 'inventario')->create(['sucursal_id' => $centro->id, 'created_at' => now()->subMinute()]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/actividad');

        $response->assertOk()->assertJsonCount(3);
        // El más reciente (el movimiento) debe ir primero.
        $this->assertStringStartsWith('movimiento_', $response->json('0.tipo'));
    }

    public function test_actividad_can_be_filtered_by_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        Venta::factory()->create(['sucursal_id' => $centro->id]);
        Venta::factory()->create(['sucursal_id' => $norte->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/actividad?sucursal_id={$centro->id}");

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.sucursal.id', $centro->id);
    }

    public function test_empleado_cannot_access_actividad(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->getJson('/api/actividad')->assertStatus(403);
    }

    public function test_actividad_shows_when_an_empleado_edits_a_venta(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $empleada = User::factory()->empleado($sucursal)->create(['username' => 'empleada_norte']);
        $venta = Venta::factory()->for($sucursal)->create(['producto' => 'Original', 'valor' => 100]);

        $this->actingAs($empleada, 'sanctum')->patchJson("/api/ventas/{$venta->id}", [
            'producto' => 'Corregido',
            'valor' => 100,
        ])->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/actividad');

        $response->assertOk();
        $evento = collect($response->json())->firstWhere('tipo', 'venta_modificado');
        $this->assertNotNull($evento, 'Debe aparecer un evento venta_modificado en el feed de Actividad.');
        $this->assertSame('empleada_norte', $evento['usuario']);
        $this->assertSame($sucursal->id, $evento['sucursal']['id']);
        $this->assertStringContainsString('Corregido', $evento['descripcion']);
    }

    public function test_actividad_shows_when_an_empleado_deletes_a_venta(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $empleada = User::factory()->empleado($sucursal)->create(['username' => 'empleada_sur']);
        $venta = Venta::factory()->for($sucursal)->create(['producto' => 'Reloj a borrar']);

        $this->actingAs($empleada, 'sanctum')->deleteJson("/api/ventas/{$venta->id}")->assertStatus(204);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/actividad');

        $response->assertOk();
        $evento = collect($response->json())->firstWhere('tipo', 'venta_eliminado');
        $this->assertNotNull($evento, 'Debe aparecer un evento venta_eliminado en el feed de Actividad.');
        $this->assertSame('empleada_sur', $evento['usuario']);
        $this->assertStringContainsString('Reloj a borrar', $evento['descripcion']);
    }
}
