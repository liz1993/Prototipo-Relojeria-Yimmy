<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReporteTest extends TestCase
{
    use RefreshDatabase;

    public function test_empleado_cannot_access_reportes(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->getJson('/api/reportes')->assertStatus(403);
    }

    public function test_utilidad_estimada_only_counts_ventas_linked_to_inventario(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['costo' => 30]);

        // Venta ligada a inventario: costo trazable (30 * 2 = 60), utilidad = 100 - 60 = 40.
        Venta::factory()->for($sucursal)->create(['inventario_id' => $item->id, 'cantidad' => 2, 'valor' => 100]);
        // Venta "producto libre": no tiene costo registrado, cuenta como ingreso puro.
        Venta::factory()->for($sucursal)->create(['inventario_id' => null, 'valor' => 50]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reportes');

        $response->assertOk();
        $this->assertSame(150.0, (float) $response->json('ventas.total'));
        $this->assertSame(60.0, (float) $response->json('ventas.costo_estimado'));
        $this->assertSame(90.0, (float) $response->json('ventas.utilidad_estimada'));
    }

    public function test_utilidad_estimada_counts_cost_of_soft_deleted_inventario(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['costo' => 10]);
        Venta::factory()->for($sucursal)->create(['inventario_id' => $item->id, 'cantidad' => 1, 'valor' => 25]);
        $item->delete();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reportes');

        $response->assertOk();
        $this->assertSame(10.0, (float) $response->json('ventas.costo_estimado'));
        $this->assertSame(15.0, (float) $response->json('ventas.utilidad_estimada'));
    }

    public function test_inventario_costo_total_reflects_current_stock(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Inventario::factory()->for($sucursal)->create(['cantidad' => 4, 'precio' => 50, 'costo' => 20]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reportes');

        $response->assertOk();
        $this->assertSame(200.0, (float) $response->json('inventario.valor_total'));
        $this->assertSame(80.0, (float) $response->json('inventario.costo_total'));
    }

    public function test_reportes_scoped_by_sucursal_id(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Venta::factory()->for($centro)->create(['valor' => 100]);
        Venta::factory()->for($norte)->create(['valor' => 500]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/reportes?sucursal_id='.$centro->id);

        $response->assertOk();
        $this->assertSame(100.0, (float) $response->json('ventas.total'));
    }
}
