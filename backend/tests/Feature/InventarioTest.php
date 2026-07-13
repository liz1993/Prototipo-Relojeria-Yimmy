<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_inventario_item(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'codigo' => 'REL-001',
            'descripcion' => 'Reloj de pulsera',
            'cantidad' => 10,
            'precio' => 49.99,
        ]);

        $response->assertCreated()->assertJsonPath('codigo', 'REL-001');
        $this->assertDatabaseHas('inventario', ['codigo' => 'REL-001', 'sucursal_id' => $sucursal->id]);
    }

    public function test_codigo_must_be_unique_within_the_same_sucursal(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Inventario::factory()->for($sucursal)->create(['codigo' => 'REL-001']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'codigo' => 'REL-001',
            'descripcion' => 'Duplicado',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_codigo_can_repeat_across_different_sucursales(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Inventario::factory()->for($centro)->create(['codigo' => 'REL-001']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $norte->id,
            'codigo' => 'REL-001',
            'descripcion' => 'Mismo código, otra sucursal',
        ]);

        $response->assertCreated();
    }

    public function test_admin_can_update_inventario_item(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 5]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/inventario/{$item->id}", [
            'sucursal_id' => $sucursal->id,
            'codigo' => $item->codigo,
            'descripcion' => $item->descripcion,
            'cantidad' => 20,
        ]);

        $response->assertOk()->assertJsonPath('cantidad', 20);
    }

    public function test_admin_can_soft_delete_inventario_item(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create();

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/inventario/{$item->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('inventario', ['id' => $item->id]);
        $this->assertDatabaseCount('inventario', 1);
    }

    public function test_admin_can_set_costo_and_sees_it_back(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'codigo' => 'REL-002',
            'descripcion' => 'Reloj con costo',
            'precio' => 100,
            'costo' => 60,
        ]);

        $response->assertCreated()->assertJsonPath('costo', '60.00');
    }

    public function test_empleado_never_sees_costo_in_the_inventario_listing(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        Inventario::factory()->for($sucursal)->create(['precio' => 100, 'costo' => 60]);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/inventario');

        $response->assertOk();
        $this->assertArrayNotHasKey('costo', $response->json('0'));
    }

    public function test_admin_sees_costo_in_the_inventario_listing(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Inventario::factory()->for($sucursal)->create(['precio' => 100, 'costo' => 60]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/inventario');

        $response->assertOk()->assertJsonPath('0.costo', '60.00');
    }

    public function test_empleado_can_read_but_not_write_inventario(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        Inventario::factory()->for($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->getJson('/api/inventario')->assertOk();
        $this->actingAs($empleado, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'codigo' => 'NO-1',
            'descripcion' => 'no autorizado',
        ])->assertStatus(403);
    }
}
