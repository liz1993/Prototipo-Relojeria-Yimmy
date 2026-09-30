<?php

namespace Tests\Feature;

use App\Models\Inventario;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            'descripcion' => 'Reloj de pulsera',
            'cantidad' => 10,
            'precio' => 49.99,
        ]);

        $response->assertCreated();
        $codigo = $response->json('codigo');
        $this->assertMatchesRegularExpression('/^INV-\d{5}$/', $codigo);
        $this->assertDatabaseHas('inventario', ['codigo' => $codigo, 'sucursal_id' => $sucursal->id]);
    }

    public function test_codigo_is_generated_by_the_system_and_ignores_client_input(): void
    {
        // El código no se acepta del cliente: aunque se mande, el backend lo
        // pisa con el que genera a partir del id -- así queda garantizado que
        // nunca se repite sin depender de que quien lo escribe no se equivoque.
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $sucursal->id,
            'codigo' => 'LO-QUE-SEA',
            'descripcion' => 'Reloj de pulsera',
        ]);

        $response->assertCreated();
        $this->assertNotSame('LO-QUE-SEA', $response->json('codigo'));
    }

    public function test_codigos_generados_nunca_se_repiten_ni_entre_sucursales(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $codigo1 = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $centro->id,
            'descripcion' => 'Producto 1',
        ])->json('codigo');

        $codigo2 = $this->actingAs($admin, 'sanctum')->postJson('/api/inventario', [
            'sucursal_id' => $norte->id,
            'descripcion' => 'Producto 2',
        ])->json('codigo');

        $this->assertNotSame($codigo1, $codigo2);
    }

    public function test_admin_can_update_inventario_item(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['cantidad' => 5]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/inventario/{$item->id}", [
            'sucursal_id' => $sucursal->id,
            'descripcion' => $item->descripcion,
            'cantidad' => 20,
        ]);

        // El código no se manda en la edición y no debe cambiar: se generó una sola vez, al crear.
        $response->assertOk()->assertJsonPath('cantidad', 20)->assertJsonPath('codigo', $item->codigo);
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

    public function test_replacing_foto_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $item = Inventario::factory()->for($sucursal)->create(['foto' => null]);

        // put() (no putJson): la carga de archivos necesita ir como datos de
        // formulario, no como JSON, para que Laravel reconozca el UploadedFile.
        // create() con mimeType explícito (no image()): image() usa GD para
        // generar píxeles reales, que no está instalado en este entorno; create()
        // basta para pasar la regla de validación 'image' sin necesitar GD.
        $primera = UploadedFile::fake()->create('primera.jpg', 10, 'image/jpeg');
        $this->actingAs($admin, 'sanctum')->put("/api/inventario/{$item->id}", [
            'sucursal_id' => $sucursal->id,
            'codigo' => $item->codigo,
            'descripcion' => $item->descripcion,
            'foto' => $primera,
        ])->assertOk();
        $rutaPrimera = $item->fresh()->foto;
        Storage::disk('public')->assertExists($rutaPrimera);

        $segunda = UploadedFile::fake()->create('segunda.jpg', 10, 'image/jpeg');
        $this->actingAs($admin, 'sanctum')->put("/api/inventario/{$item->id}", [
            'sucursal_id' => $sucursal->id,
            'codigo' => $item->codigo,
            'descripcion' => $item->descripcion,
            'foto' => $segunda,
        ])->assertOk();

        Storage::disk('public')->assertMissing($rutaPrimera);
        Storage::disk('public')->assertExists($item->fresh()->foto);
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

    public function test_empleado_can_check_disponibilidad_in_other_sucursales(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($centro)->create();
        Inventario::factory()->for($norte)->create(['descripcion' => 'Reloj Casio G-Shock', 'cantidad' => 3]);

        // El buscador es cruzado: aunque el empleado sea de "centro", debe
        // aparecer el resultado que está en "norte" -- a diferencia de
        // index(), acá no se filtra por sucursal propia.
        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/inventario/disponibilidad?buscar=G-Shock');

        $response->assertOk()->assertJsonCount(1)->assertJsonPath('0.sucursal.id', $norte->id);
    }

    public function test_disponibilidad_never_exposes_costo(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Inventario::factory()->for($sucursal)->create(['descripcion' => 'Reloj Seiko', 'costo' => 60, 'cantidad' => 5]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/inventario/disponibilidad?buscar=Seiko');

        $response->assertOk();
        $this->assertArrayNotHasKey('costo', $response->json('0'));
    }

    public function test_disponibilidad_excludes_items_sin_stock(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        Inventario::factory()->for($sucursal)->create(['descripcion' => 'Reloj Agotado', 'cantidad' => 0]);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/inventario/disponibilidad?buscar=Agotado');

        $response->assertOk()->assertJsonCount(0);
    }

    public function test_disponibilidad_requires_a_search_term(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/inventario/disponibilidad');

        $response->assertStatus(422)->assertJsonValidationErrors('buscar');
    }

    public function test_admin_can_export_inventario_csv(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        Inventario::factory()->for($sucursal)->create(['codigo' => 'REL-001', 'descripcion' => 'Reloj exportable']);

        $response = $this->actingAs($admin, 'sanctum')->get('/api/inventario/exportar');

        $response->assertOk();
        $this->assertStringContainsString('REL-001', $response->streamedContent());
        $this->assertStringContainsString('Reloj exportable', $response->streamedContent());
    }

    public function test_empleado_cannot_export_inventario_csv(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        $this->actingAs($empleado, 'sanctum')->get('/api/inventario/exportar')->assertStatus(403);
    }

    public function test_admin_can_import_inventario_csv_creating_and_updating_by_codigo(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        // Este código ya existe en la sucursal: la fila del CSV debe actualizarlo en vez de duplicarlo.
        Inventario::factory()->for($sucursal)->create(['codigo' => 'REL-001', 'descripcion' => 'Viejo', 'cantidad' => 1, 'precio' => 10]);

        $csv = "Código,Descripción,Cantidad,Precio,Costo\n"
            ."REL-001,Reloj actualizado,5,50,30\n"
            ."REL-002,Reloj nuevo,3,80,40\n";
        $archivo = UploadedFile::fake()->createWithContent('inventario.csv', $csv);

        $response = $this->actingAs($admin, 'sanctum')->post('/api/inventario/importar', [
            'sucursal_id' => $sucursal->id,
            'archivo' => $archivo,
        ]);

        $response->assertOk()->assertJson(['creados' => 1, 'actualizados' => 1, 'errores' => []]);
        $this->assertDatabaseHas('inventario', ['codigo' => 'REL-001', 'sucursal_id' => $sucursal->id, 'descripcion' => 'Reloj actualizado', 'cantidad' => 5]);
        $this->assertDatabaseHas('inventario', ['codigo' => 'REL-002', 'sucursal_id' => $sucursal->id, 'descripcion' => 'Reloj nuevo', 'cantidad' => 3]);
    }

    public function test_importar_inventario_reports_error_for_row_missing_descripcion(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $csv = "Código,Descripción,Cantidad,Precio\nREL-003,,1,10\n";
        $archivo = UploadedFile::fake()->createWithContent('inventario.csv', $csv);

        $response = $this->actingAs($admin, 'sanctum')->post('/api/inventario/importar', [
            'sucursal_id' => $sucursal->id,
            'archivo' => $archivo,
        ]);

        $response->assertOk()->assertJson(['creados' => 0, 'actualizados' => 0]);
        $this->assertNotEmpty($response->json('errores'));
        $this->assertDatabaseMissing('inventario', ['codigo' => 'REL-003']);
    }

    public function test_empleado_cannot_import_inventario_csv(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $archivo = UploadedFile::fake()->createWithContent('inventario.csv', "Código,Descripción\nREL-004,No autorizado\n");

        $this->actingAs($empleado, 'sanctum')->post('/api/inventario/importar', [
            'sucursal_id' => $sucursal->id,
            'archivo' => $archivo,
        ])->assertStatus(403);
    }
}
