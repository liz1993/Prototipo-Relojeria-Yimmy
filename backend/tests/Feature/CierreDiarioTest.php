<?php

namespace Tests\Feature;

use App\Models\Sucursal;
use App\Models\User;
use App\Models\Venta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CierreDiarioTest extends TestCase
{
    use RefreshDatabase;

    public function test_cierre_del_dia_separa_total_nequi_y_efectivo(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        Venta::factory()->for($sucursal)->create(['fecha' => '2026-08-01', 'valor' => 100, 'metodo_pago' => 'efectivo']);
        Venta::factory()->for($sucursal)->create(['fecha' => '2026-08-01', 'valor' => 40, 'metodo_pago' => 'nequi']);
        Venta::factory()->for($sucursal)->create(['fecha' => '2026-08-01', 'valor' => 25, 'metodo_pago' => 'nequi']);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/cierres');

        $response->assertOk();
        // No se compara "dia" con igualdad exacta: el cast 'date' de Venta lo
        // guarda como datetime completo en SQLite ("2026-08-01 00:00:00").
        $dia = collect($response->json())->first(fn ($d) => str_starts_with($d['dia'], '2026-08-01'));
        $this->assertNotNull($dia);
        $this->assertSame(165.0, (float) $dia['total']);
        $this->assertSame(65.0, (float) $dia['total_nequi']);
        $this->assertSame(100.0, (float) $dia['total_efectivo']);
    }

    public function test_venta_sin_metodo_pago_explicito_cuenta_como_efectivo(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Reloj de pulsera',
            'valor' => 75,
            'sucursal_id' => $sucursal->id,
        ])->assertCreated()->assertJsonPath('metodo_pago', 'efectivo');
    }

    public function test_venta_puede_registrarse_explicitamente_como_nequi(): void
    {
        $sucursal = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson('/api/ventas', [
            'producto' => 'Reloj de pulsera',
            'valor' => 75,
            'sucursal_id' => $sucursal->id,
            'metodo_pago' => 'nequi',
        ])->assertCreated()->assertJsonPath('metodo_pago', 'nequi');
    }

    public function test_empleado_can_see_cierre_de_hoy_de_su_propia_sucursal(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();
        $hoy = now()->toDateString();

        Venta::factory()->for($sucursal)->create(['fecha' => $hoy, 'valor' => 100, 'metodo_pago' => 'efectivo']);
        Venta::factory()->for($sucursal)->create(['fecha' => $hoy, 'valor' => 40, 'metodo_pago' => 'nequi']);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/cierres/hoy');

        $response->assertOk();
        $this->assertSame(140.0, (float) $response->json('total'));
        $this->assertSame(40.0, (float) $response->json('total_nequi'));
        $this->assertSame(100.0, (float) $response->json('total_efectivo'));
    }

    public function test_empleado_no_ve_ventas_de_otra_sucursal_en_cierre_de_hoy(): void
    {
        $suya = Sucursal::factory()->create();
        $otra = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($suya)->create();
        $hoy = now()->toDateString();

        Venta::factory()->for($otra)->create(['fecha' => $hoy, 'valor' => 500, 'metodo_pago' => 'nequi']);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/cierres/hoy');

        $response->assertOk();
        $this->assertSame(0.0, (float) $response->json('total'));
    }

    public function test_cierre_de_hoy_no_incluye_ventas_de_otros_dias(): void
    {
        $sucursal = Sucursal::factory()->create();
        $empleado = User::factory()->empleado($sucursal)->create();

        Venta::factory()->for($sucursal)->create(['fecha' => now()->subDay()->toDateString(), 'valor' => 300]);

        $response = $this->actingAs($empleado, 'sanctum')->getJson('/api/cierres/hoy');

        $response->assertOk()->assertJsonPath('total', 0);
    }

    public function test_admin_can_filter_cierre_de_hoy_by_sucursal(): void
    {
        $centro = Sucursal::factory()->create();
        $norte = Sucursal::factory()->create();
        $admin = User::factory()->admin()->create();
        $hoy = now()->toDateString();

        Venta::factory()->for($centro)->create(['fecha' => $hoy, 'valor' => 100]);
        Venta::factory()->for($norte)->create(['fecha' => $hoy, 'valor' => 50]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/cierres/hoy?sucursal_id=' . $centro->id);

        $response->assertOk()->assertJsonPath('total', 100);
    }
}
