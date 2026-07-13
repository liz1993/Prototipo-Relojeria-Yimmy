<?php

namespace Tests\Feature;

use App\Models\Reparacion;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReparacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_empleado_can_create_reparacion_defaulting_to_pendiente(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/reparaciones', [
            'cliente' => 'Juan Pérez',
            'modelo' => 'Casio F-91W',
            'valor_total' => 50,
            'abono' => 20,
        ]);

        $response->assertCreated()
            ->assertJsonPath('estado', 'pendiente')
            ->assertJsonPath('saldo', '30.00');
        $this->assertDatabaseHas('reparaciones', ['cliente' => 'Juan Pérez', 'sucursal_id' => $sucursal->id]);
    }

    public function test_observaciones_can_be_set_at_creation_time(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/reparaciones', [
            'cliente' => 'Cliente con nota inicial',
            'observaciones' => 'Golpe en la caja, se revisa mecanismo.',
        ]);

        $response->assertCreated()->assertJsonPath('observaciones', 'Golpe en la caja, se revisa mecanismo.');
    }

    public function test_cedula_can_be_set_at_creation_time(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->postJson('/api/reparaciones', [
            'cliente' => 'Cliente con cedula',
            'cedula' => '1234567890',
        ]);

        $response->assertCreated()->assertJsonPath('cedula', '1234567890');
    }

    public function test_admin_must_specify_sucursal_when_creating_a_reparacion(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/reparaciones', [
            'cliente' => 'Sin sucursal',
        ])->assertStatus(422)->assertJsonValidationErrors('sucursal_id');

        $this->actingAs($admin, 'sanctum')->postJson('/api/reparaciones', [
            'cliente' => 'Con sucursal',
            'sucursal_id' => $sucursal->id,
        ])->assertCreated();
    }

    public function test_estado_must_be_a_known_value(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $reparacion = Reparacion::factory()->for($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')
            ->putJson("/api/reparaciones/{$reparacion->id}", ['estado' => 'inventado']);

        $response->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_empleado_can_write_observaciones_when_reviewing_a_reparacion(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $reparacion = Reparacion::factory()->for($sucursal)->create(['estado' => 'pendiente']);

        $response = $this->actingAs($empleado, 'sanctum')->putJson("/api/reparaciones/{$reparacion->id}", [
            'estado' => 'en_proceso',
            'observaciones' => 'Cristal rayado, cliente informado.',
        ]);

        $response->assertOk()->assertJsonPath('observaciones', 'Cristal rayado, cliente informado.');
    }

    public function test_empleado_cannot_write_observaciones_on_a_reparacion_from_another_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleadoCentro = User::factory()->empleado($centro)->create();
        $reparacionNorte = Reparacion::factory()->for($norte)->create(['observaciones' => null]);

        $response = $this->actingAs($empleadoCentro, 'sanctum')->putJson("/api/reparaciones/{$reparacionNorte->id}", [
            'estado' => 'en_proceso',
            'observaciones' => 'Intento no autorizado',
        ]);

        $response->assertStatus(404);
        $this->assertNull($reparacionNorte->fresh()->observaciones);
    }

    public function test_admin_can_also_write_observaciones(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $reparacion = Reparacion::factory()->for($sucursal)->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/reparaciones/{$reparacion->id}", [
            'estado' => $reparacion->estado,
            'observaciones' => 'Requiere pieza importada.',
            'cliente' => $reparacion->cliente,
        ]);

        $response->assertOk()->assertJsonPath('observaciones', 'Requiere pieza importada.');
    }

    public function test_admin_can_edit_full_reparacion_record(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $reparacion = Reparacion::factory()->for($sucursal)->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/reparaciones/{$reparacion->id}", [
            'estado' => 'listo',
            'cliente' => 'Cliente Actualizado',
            'valor_total' => 200,
            'abono' => 100,
        ]);

        $response->assertOk()
            ->assertJsonPath('cliente', 'Cliente Actualizado')
            ->assertJsonPath('estado', 'listo');
    }

    public function test_admin_can_upload_a_photo_when_creating_a_reparacion(): void
    {
        Storage::fake('public');
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->post('/api/reparaciones', [
            'cliente' => 'Con Foto',
            'sucursal_id' => $sucursal->id,
            // create() en vez de image(): el PHP de XAMPP no trae la extensión GD
            // habilitada en CLI, así que fake()->image() (que renderiza un PNG real)
            // revienta con "GD extension is not installed". create() con un mimeType
            // explícito basta para pasar la regla de validación 'image' sin generar
            // píxeles de verdad.
            'foto' => UploadedFile::fake()->create('reloj.jpg', 10, 'image/jpeg'),
        ]);

        $response->assertCreated();
        $path = $response->json('foto');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_admin_can_delete_a_reparacion(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $reparacion = Reparacion::factory()->for($sucursal)->create();

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/reparaciones/{$reparacion->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('reparaciones', ['id' => $reparacion->id]);
    }

    public function test_consulta_by_id_matches_numeric_search_term(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $reparacion = Reparacion::factory()->for($sucursal)->create(['cliente' => 'Alguien']);

        $response = $this->actingAs($empleado, 'sanctum')
            ->getJson('/api/reparaciones?buscar='.$reparacion->id);

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $reparacion->id);
    }

    public function test_consulta_by_client_name_uses_partial_match(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        Reparacion::factory()->for($sucursal)->create(['cliente' => 'María López']);
        Reparacion::factory()->for($sucursal)->create(['cliente' => 'Pedro Gómez']);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/reparaciones?buscar=María');

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.cliente', 'María López');
    }
}
