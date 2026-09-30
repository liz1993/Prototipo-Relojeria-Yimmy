<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Movimiento;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovimientoTest extends TestCase
{
    use RefreshDatabase;

    public function test_empleado_can_register_an_entrada_and_stock_increases(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 5]);

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/movimientos', [
            'inventario_id' => $item->id,
            'tipo' => 'entrada',
            'cantidad' => 10,
            'motivo' => 'Compra a proveedor',
        ]);

        $response->assertCreated()->assertJsonPath('tipo', 'entrada');
        $this->assertEquals(15, $item->fresh()->cantidad);
    }

    public function test_registrar_salida_descuenta_stock(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 10]);

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/movimientos', [
            'inventario_id' => $item->id,
            'tipo' => 'salida',
            'cantidad' => 4,
            'motivo' => 'Trasladado a Sucursal Norte',
        ]);

        $response->assertCreated();
        $this->assertEquals(6, $item->fresh()->cantidad);
    }

    public function test_salida_rejected_when_stock_insufficient(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 2]);

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/movimientos', [
            'inventario_id' => $item->id,
            'tipo' => 'salida',
            'cantidad' => 5,
            'motivo' => 'Dañado',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('cantidad');
        $this->assertEquals(2, $item->fresh()->cantidad);
    }

    public function test_empleado_cannot_register_movimiento_for_another_sucursal(): void
    {
        $propia = Sucursal::factory()->create();
        $otra = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($propia)->create();
        $item = Inventario::factory()->for($otra)->create(['cantidad' => 10]);

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/movimientos', [
            'inventario_id' => $item->id,
            'tipo' => 'entrada',
            'cantidad' => 5,
            'motivo' => 'Intento ajeno',
        ]);

        $response->assertStatus(404);
        $this->assertEquals(10, $item->fresh()->cantidad);
    }

    public function test_empleado_only_sees_movimientos_from_own_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();
        $itemCentro = Inventario::factory()->for($centro)->create();
        $itemNorte = Inventario::factory()->for($norte)->create();
        Movimiento::factory()->for($itemCentro, 'inventario')->create(['sucursal_id' => $centro->id]);
        Movimiento::factory()->for($itemNorte, 'inventario')->create(['sucursal_id' => $norte->id]);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/movimientos');

        $response->assertOk()->assertJsonCount(1);
    }

    public function test_admin_can_delete_movimiento_and_stock_is_reverted(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 10]);
        $movimiento = Movimiento::factory()->for($item, 'inventario')->create([
            'sucursal_id' => $sucursal->id,
            'tipo' => 'entrada',
            'cantidad' => 10,
        ]);
        $item->increment('cantidad', 10); // simula el efecto que ya tuvo el movimiento al crearse

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/movimientos/{$movimiento->id}");

        $response->assertStatus(204);
        $this->assertEquals(10, $item->fresh()->cantidad);
        $this->assertDatabaseMissing('movimientos', ['id' => $movimiento->id]);
    }

    public function test_empleado_cannot_delete_movimiento(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $item = Inventario::factory()->for($sucursal)->create();
        $movimiento = Movimiento::factory()->for($item, 'inventario')->create(['sucursal_id' => $sucursal->id]);

        $this->actingAs($empleado, 'sanctum')->deleteJson("/api/movimientos/{$movimiento->id}")->assertStatus(403);
    }
}
