<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VentaTest extends TestCase
{
    use RefreshDatabase;

    public function test_venta_linked_to_inventario_decrements_stock(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 10]);

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => $item->descripcion,
            'cantidad' => 3,
            'valor' => 30,
            'inventario_id' => $item->id,
        ]);

        $response->assertCreated();
        $this->assertSame(7, $item->fresh()->cantidad);
    }

    public function test_venta_is_rejected_when_stock_is_insufficient(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 2]);

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => $item->descripcion,
            'cantidad' => 5,
            'valor' => 50,
            'inventario_id' => $item->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('cantidad');
        $this->assertSame(2, $item->fresh()->cantidad);
        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_venta_cannot_use_inventario_item_from_another_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleadoCentro = User::factory()->empleado($centro)->create();
        $itemNorte = Inventario::factory()->for($norte)->create(['cantidad' => 10]);

        $response = $this->actingAs($empleadoCentro, 'sanctum')->postJson('/api/ventas', [
            'producto' => $itemNorte->descripcion,
            'cantidad' => 1,
            'valor' => 10,
            'inventario_id' => $itemNorte->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('inventario_id');
        $this->assertSame(10, $itemNorte->fresh()->cantidad);
    }

    public function test_empleado_venta_is_forced_to_their_own_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleadoCentro = User::factory()->empleado($centro)->create();

        $response = $this->actingAs($empleadoCentro, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Correa de cuero',
            'valor' => 15,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ventas', ['id' => $response->json('id'), 'sucursal_id' => $centro->id]);
    }

    public function test_deleting_a_venta_restores_the_inventory_stock(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 5]);
        $venta = Venta::factory()->for($sucursal)->create(['inventario_id' => $item->id, 'cantidad' => 3]);
        $item->decrement('cantidad', 3);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/ventas/{$venta->id}");

        $response->assertStatus(204);
        $this->assertSame(5, $item->fresh()->cantidad);
        $this->assertSoftDeleted('ventas', ['id' => $venta->id]);
    }

    public function test_admin_can_edit_a_venta(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $venta = Venta::factory()->for($sucursal)->create(['producto' => 'Original']);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/ventas/{$venta->id}", [
            'producto' => 'Corregido',
            'valor' => 99,
        ]);

        $response->assertOk()->assertJsonPath('producto', 'Corregido');
    }

    public function test_empleado_cannot_edit_a_venta(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $venta = Venta::factory()->for($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->patchJson("/api/ventas/{$venta->id}", [
            'producto' => 'Intento',
        ])->assertStatus(403);
    }
}
