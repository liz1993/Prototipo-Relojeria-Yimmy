<?php

namespace Tests\Feature;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        $user = User::factory()->empleado()->create([
            'username' => 'empleado_centro',
            'password' => Hash::make('secreto'),
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'empleado_centro',
            'password' => 'secreto',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'username', 'tipo', 'sucursal']])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.tipo', 'empleado');
    }

    public function test_login_with_invalid_password_is_rejected(): void
    {
        User::factory()->empleado()->create([
            'username' => 'empleado_centro',
            'password' => Hash::make('secreto'),
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'empleado_centro',
            'password' => 'incorrecta',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Credenciales incorrectas.']);
    }

    public function test_login_with_unknown_username_is_rejected(): void
    {
        $response = $this->postJson('/api/login', [
            'username' => 'no-existe',
            'password' => 'lo-que-sea',
        ]);

        $response->assertStatus(401);
    }

    public function test_register_creates_empleado_scoped_to_chosen_sucursal(): void
    {
        $sucursal = Sucursal::factory()->create();

        $response = $this->postJson('/api/register', [
            'name' => 'Nuevo Empleado',
            'username' => 'nuevo_empleado',
            'email' => 'nuevo_empleado@example.com',
            'password' => '123',
            'sucursal_id' => $sucursal->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.tipo', 'empleado')
            ->assertJsonPath('user.sucursal.id', $sucursal->id);

        $this->assertDatabaseHas('users', [
            'username' => 'nuevo_empleado',
            'email' => 'nuevo_empleado@example.com',
            'tipo' => 'empleado',
            'sucursal_id' => $sucursal->id,
        ]);
    }

    public function test_register_rejects_duplicate_username(): void
    {
        $sucursal = Sucursal::factory()->create();
        User::factory()->create(['username' => 'repetido']);

        $response = $this->postJson('/api/register', [
            'username' => 'repetido',
            'email' => 'repetido@example.com',
            'password' => '123',
            'sucursal_id' => $sucursal->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $sucursal = Sucursal::factory()->create();
        User::factory()->create(['email' => 'repetido@example.com']);

        $response = $this->postJson('/api/register', [
            'username' => 'usuario_nuevo',
            'email' => 'repetido@example.com',
            'password' => '123',
            'sucursal_id' => $sucursal->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_register_requires_a_valid_sucursal(): void
    {
        $response = $this->postJson('/api/register', [
            'username' => 'sin_sucursal',
            'email' => 'sin_sucursal@example.com',
            'password' => '123',
            'sucursal_id' => 9999,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('sucursal_id');
    }

    public function test_authenticated_user_can_fetch_own_profile(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/user');

        $response->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('tipo', 'admin');
    }

    public function test_unauthenticated_request_to_protected_route_is_rejected(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertStatus(401);
    }

    public function test_login_is_throttled_after_repeated_attempts(): void
    {
        User::factory()->empleado()->create([
            'username' => 'empleado_centro',
            'password' => Hash::make('secreto'),
        ]);

        // La ruta está limitada a 6 intentos por minuto por IP (throttle:6,1).
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['username' => 'empleado_centro', 'password' => 'incorrecta'])
                ->assertStatus(401);
        }

        $this->postJson('/api/login', ['username' => 'empleado_centro', 'password' => 'incorrecta'])
            ->assertStatus(429);

        // Ni siquiera con la contraseña correcta pasa una vez agotado el límite.
        $this->postJson('/api/login', ['username' => 'empleado_centro', 'password' => 'secreto'])
            ->assertStatus(429);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->admin()->create();
        $token = $user->createToken('web');

        $response = $this->withHeader('Authorization', "Bearer {$token->plainTextToken}")->postJson('/api/logout');
        $response->assertOk();

        // No se repite la llamada HTTP con el mismo bearer token: el guard de
        // Sanctum (RequestGuard) cachea el usuario resuelto en la instancia
        // del guard, y dentro de un mismo test method la app/contenedor no se
        // reinicia entre llamadas, así que una segunda petición "vería" el
        // usuario cacheado en vez de re-resolver el token ya borrado — eso
        // nunca pasa en producción real, donde cada request tiene su propia
        // instancia de la app. Se verifica directamente que el token ya no
        // exista en la base de datos.
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
