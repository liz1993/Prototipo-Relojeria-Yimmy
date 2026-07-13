<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\SolicitudPassword;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_sucursal(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/sucursales', [
            'nombre' => 'Sucursal Mall',
            'direccion' => 'Av. Principal 123',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('sucursales', ['nombre' => 'Sucursal Mall']);
    }

    public function test_sucursales_index_is_public_for_registration(): void
    {
        // La migración de sucursales ya inserta 4 filas por defecto ("Sucursal 1".."4"),
        // así que verificamos el incremento en vez de un conteo absoluto.
        $antes = Sucursal::count();
        Sucursal::factory()->count(2)->create();

        $response = $this->getJson('/api/sucursales');

        $response->assertOk()->assertJsonCount($antes + 2);
    }

    public function test_admin_can_create_and_update_users(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/usuarios', [
            'name' => 'Empleado Nuevo',
            'username' => 'empleado_nuevo',
            'password' => '123',
            'tipo' => 'empleado',
            'sucursal_id' => $sucursal->id,
        ]);
        $create->assertCreated();
        $userId = $create->json('id');

        $update = $this->actingAs($admin, 'sanctum')->putJson("/api/usuarios/{$userId}", [
            'name' => 'Empleado Renombrado',
            'username' => 'empleado_nuevo',
            'tipo' => 'admin',
            'sucursal_id' => null,
        ]);

        $update->assertOk()->assertJsonPath('name', 'Empleado Renombrado')->assertJsonPath('tipo', 'admin');
        $this->assertDatabaseHas('users', ['id' => $userId, 'tipo' => 'admin', 'sucursal_id' => null]);
    }

    public function test_empleado_type_user_requires_a_sucursal(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/usuarios', [
            'name' => 'Sin Sucursal',
            'username' => 'sin_sucursal_user',
            'password' => '123',
            'tipo' => 'empleado',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
    }

    public function test_empleado_cannot_list_or_create_users(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->getJson('/api/usuarios')->assertStatus(403);
        $this->actingAs($empleado, 'sanctum')->postJson('/api/usuarios', [
            'name' => 'x', 'username' => 'x', 'password' => '123', 'tipo' => 'empleado', 'sucursal_id' => $sucursal->id,
        ])->assertStatus(403);
    }

    public function test_soft_deleted_items_appear_in_papelera_and_can_be_restored(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create();
        $item->delete();

        $index = $this->actingAs($admin, 'sanctum')->getJson('/api/papelera');
        $index->assertOk()->assertJsonCount(1)->assertJsonPath('0.tipo', 'inventario');

        $restore = $this->actingAs($admin, 'sanctum')->postJson("/api/papelera/inventario/{$item->id}/restaurar");
        $restore->assertOk();

        $this->assertDatabaseHas('inventario', ['id' => $item->id, 'deleted_at' => null]);
    }

    public function test_papelera_rejects_an_unknown_tipo(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/papelera/no-existe/1/restaurar')->assertStatus(404);
    }

    public function test_solicitud_password_creates_a_pending_request_for_an_existing_user(): void
    {
        $user = User::factory()->create(['username' => 'empleado_centro']);

        $response = $this->postJson('/api/olvide-password', ['username' => 'empleado_centro']);

        $response->assertOk();
        $this->assertDatabaseHas('solicitudes_password', ['user_id' => $user->id, 'atendida_en' => null]);
    }

    public function test_solicitud_password_responds_the_same_for_an_unknown_user(): void
    {
        $response = $this->postJson('/api/olvide-password', ['username' => 'no-existe']);

        $response->assertOk()->assertJson(['message' => 'Si el usuario existe, quedó avisado el administrador para que te contacte.']);
        $this->assertDatabaseCount('solicitudes_password', 0);
    }

    public function test_solicitud_password_does_not_duplicate_pending_requests(): void
    {
        $user = User::factory()->create(['username' => 'empleado_centro']);

        $this->postJson('/api/olvide-password', ['username' => 'empleado_centro']);
        $this->postJson('/api/olvide-password', ['username' => 'empleado_centro']);

        $this->assertDatabaseCount('solicitudes_password', 1);
    }

    public function test_admin_can_list_and_atender_solicitudes(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $solicitud = SolicitudPassword::create(['user_id' => $user->id]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/solicitudes-password')->assertOk()->assertJsonCount(1);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/solicitudes-password/{$solicitud->id}/atender");
        $response->assertOk()->assertJsonPath('atendida_en', fn ($value) => $value !== null);

        $this->actingAs($admin, 'sanctum')->getJson('/api/solicitudes-password')->assertOk()->assertJsonCount(0);
    }

    public function test_empleado_cannot_view_password_recovery_requests(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->getJson('/api/solicitudes-password')->assertStatus(403);
    }
}
