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

    public function test_empleado_can_edit_a_venta_from_own_sucursal(): void
    {
        // Para poder cuadrar caja si se equivocó al registrarla -- ver
        // VentaObserver, que deja esto anotado en el feed de Actividad.
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $venta = Venta::factory()->for($sucursal)->create(['producto' => 'Original']);

        $this->actingAs($empleado, 'sanctum')->patchJson("/api/ventas/{$venta->id}", [
            'producto' => 'Corregido',
        ])->assertOk()->assertJsonPath('producto', 'Corregido');
    }

    public function test_empleado_cannot_edit_a_venta_from_another_sucursal(): void
    {
        $suya = Sucursal::factory()->create();
        $otra = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($suya)->create();
        $venta = Venta::factory()->for($otra)->create();

        $this->actingAs($empleado, 'sanctum')->patchJson("/api/ventas/{$venta->id}", [
            'producto' => 'Intento',
        ])->assertStatus(404);
    }

    public function test_empleado_can_delete_a_venta_from_own_sucursal(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $venta = Venta::factory()->for($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->deleteJson("/api/ventas/{$venta->id}")->assertStatus(204);
        $this->assertSoftDeleted('ventas', ['id' => $venta->id]);
    }

    public function test_empleado_cannot_delete_a_venta_from_another_sucursal(): void
    {
        $suya = Sucursal::factory()->create();
        $otra = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($suya)->create();
        $venta = Venta::factory()->for($otra)->create();

        $this->actingAs($empleado, 'sanctum')->deleteJson("/api/ventas/{$venta->id}")->assertStatus(404);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);
    }

    public function test_restoring_a_venta_from_papelera_decrements_stock_again(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 5]);
        $venta = Venta::factory()->for($sucursal)->create(['inventario_id' => $item->id, 'cantidad' => 3]);
        $item->decrement('cantidad', 3); // simula el estado tras la venta original: quedan 2.

        // Borrar la venta le devuelve el stock (5 de nuevo).
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/ventas/{$venta->id}")->assertStatus(204);
        $this->assertSame(5, $item->fresh()->cantidad);

        // Restaurarla debe volver a descontarlo -- si no, queda "stock fantasma".
        $restore = $this->actingAs($admin, 'sanctum')->postJson("/api/papelera/ventas/{$venta->id}/restaurar");

        $restore->assertOk();
        $this->assertSame(2, $item->fresh()->cantidad);
        $this->assertDatabaseHas('ventas', ['id' => $venta->id, 'deleted_at' => null]);
    }

    public function test_restoring_a_venta_from_papelera_fails_when_stock_is_now_insufficient(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 5]);
        $venta = Venta::factory()->for($sucursal)->create(['inventario_id' => $item->id, 'cantidad' => 3]);
        $item->decrement('cantidad', 3);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/ventas/{$venta->id}");
        // Otra venta se lleva casi todo el stock que se había devuelto.
        $item->decrement('cantidad', 4);

        $restore = $this->actingAs($admin, 'sanctum')->postJson("/api/papelera/ventas/{$venta->id}/restaurar");

        $restore->assertStatus(422)->assertJsonValidationErrors('cantidad');
        $this->assertSoftDeleted('ventas', ['id' => $venta->id]);
        $this->assertSame(1, $item->fresh()->cantidad);
    }

    public function test_selling_a_soft_deleted_inventario_item_is_rejected_with_a_clean_error(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 10]);
        $item->delete();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Lo que sea',
            'cantidad' => 1,
            'valor' => 10,
            'inventario_id' => $item->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('inventario_id');
    }

    public function test_index_can_be_filtered_by_fecha_range(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Venta::factory()->for($sucursal)->create(['fecha' => '2026-07-01', 'producto' => 'Vieja']);
        Venta::factory()->for($sucursal)->create(['fecha' => '2026-07-15', 'producto' => 'En rango']);
        Venta::factory()->for($sucursal)->create(['fecha' => '2026-08-01', 'producto' => 'Futura']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ventas?desde=2026-07-10&hasta=2026-07-20');

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.producto', 'En rango');
    }

    public function test_index_can_be_filtered_by_empleado(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $empleadoA = User::factory()->empleado($sucursal)->create();
        $empleadoB = User::factory()->empleado($sucursal)->create();
        Venta::factory()->for($sucursal)->create(['user_id' => $empleadoA->id, 'producto' => 'De A']);
        Venta::factory()->for($sucursal)->create(['user_id' => $empleadoB->id, 'producto' => 'De B']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/ventas?user_id='.$empleadoA->id);

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.producto', 'De A');
    }

    public function test_empleado_can_also_filter_by_empleado_within_their_own_sucursal(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleadoA = User::factory()->empleado($sucursal)->create();
        $empleadoB = User::factory()->empleado($sucursal)->create();
        Venta::factory()->for($sucursal)->create(['user_id' => $empleadoA->id]);
        Venta::factory()->for($sucursal)->create(['user_id' => $empleadoB->id]);

        $response = $this->actingAs($empleadoA, 'sanctum')->getJson('/api/ventas?user_id='.$empleadoB->id);

        // El filtro por sucursal propia sigue mandando -- ve la venta de B porque
        // es de la misma sucursal, no porque pueda saltarse el scoping.
        $response->assertOk()->assertJsonCount(1);
    }

    public function test_venta_defaults_to_tipo_venta_when_not_sent(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Reloj Casio',
            'valor' => 50,
        ]);

        $response->assertCreated()->assertJsonPath('tipo', 'venta');
    }

    public function test_venta_can_be_registered_as_arreglo_with_observaciones(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Cambio de pila',
            'valor' => 5,
            'tipo' => 'arreglo',
            'observaciones' => 'Pila SR626, cliente esperó en el local.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('tipo', 'arreglo')
            ->assertJsonPath('observaciones', 'Pila SR626, cliente esperó en el local.');
    }

    public function test_admin_can_edit_tipo_and_observaciones_of_a_venta(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $venta = Venta::factory()->for($sucursal)->create(['tipo' => 'venta', 'observaciones' => null]);

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/ventas/{$venta->id}", [
            'producto' => $venta->producto,
            'tipo' => 'arreglo',
            'observaciones' => 'Se corrigió después de entregarlo.',
        ]);

        $response->assertOk()
            ->assertJsonPath('tipo', 'arreglo')
            ->assertJsonPath('observaciones', 'Se corrigió después de entregarlo.');
    }
}
