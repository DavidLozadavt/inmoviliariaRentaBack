<?php

namespace Tests\Feature\Visor3d;

use App\Models\Building;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Puente ERP → NexiLiving: el proyecto vertical del ERP crea o actualiza su edificio 3D. */
class ErpEdificioBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $unidades = []): array
    {
        return [
            'erp_ref' => 'vt:1:45',
            'name' => 'Torre Altavista',
            'city' => 'Popayán',
            'propietario' => ['email' => 'inmo@test.local', 'nombre' => 'Inmobiliaria Prueba', 'id_documento' => '900111222'],
            'config' => [
                'floor_height' => 2.8,
                'towers' => [['code' => 'T1', 'floors' => 3, 'units_per_floor' => 2, 'layout' => ['A', 'B']]],
                'special_floors' => [['tower' => null, 'floor' => 1, 'kind' => 'lobby']],
            ],
            'unit_types' => [['code' => 'A', 'area_m2' => 70, 'bedrooms' => 3, 'bathrooms' => 2], ['code' => 'B', 'area_m2' => 55, 'bedrooms' => 2, 'bathrooms' => 1]],
            'unidades' => $unidades,
        ];
    }

    private function enviar(array $payload, ?string $token = null)
    {
        return $this->postJson('/api/integration/edificios', $payload, ['X-Bridge-Token' => $token ?? config('services.bridge.token')]);
    }

    public function test_exige_el_token_del_puente(): void
    {
        $this->enviar($this->payload(), 'otro')->assertStatus(401);
        $this->assertSame(0, Building::count());
    }

    public function test_crea_el_edificio_con_unidades_y_es_idempotente(): void
    {
        $r = $this->enviar($this->payload([
            ['tower' => 'T1', 'unit_code' => '201', 'estado' => 'reservado', 'precio' => 300000000, 'publicado' => true],
            ['tower' => 'T9', 'unit_code' => '101', 'estado' => 'disponible', 'publicado' => true],
        ]))->assertCreated()->assertJsonPath('unidades', 4)->assertJsonPath('sin_posicion', ['T9-101']);

        $this->assertStringContainsString('/visor/', $r->json('visor_url'));
        $dueno = User::where('email', 'inmo@test.local')->firstOrFail();
        $this->assertSame('landlord', $dueno->role);
        $b = Building::where('erp_ref', 'vt:1:45')->firstOrFail();
        $this->assertSame($dueno->id, $b->user_id);
        $u = Property::where('building_id', $b->id)->where('tower', 'T1')->where('unit_code', '201')->firstOrFail();
        $this->assertSame('reserved', $u->commercial_status);
        $this->assertSame('published', $u->visibility);

        // Reenviar: actualiza el mismo edificio y el estado, sin duplicar unidades.
        $this->enviar($this->payload([['tower' => 'T1', 'unit_code' => '201', 'estado' => 'vendido', 'publicado' => true]]))
            ->assertOk()->assertJsonPath('creado', false)->assertJsonPath('nuevas', 0);
        $this->assertSame(1, Building::count());
        $this->assertSame(4, Property::where('building_id', $b->id)->count());
        $this->assertSame('sold', $u->fresh()->commercial_status);
    }
}
