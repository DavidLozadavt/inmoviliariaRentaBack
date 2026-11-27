<?php

namespace Tests\Feature\Visor3d;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingConfigTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    private function config(): array
    {
        return [
            'floor_height'   => 3,
            'colors'         => ['facade' => '#D9D4CC'],
            'towers'         => [['code' => 'T1', 'floors' => 5, 'units_per_floor' => 2, 'layout' => ['A', 'B']]],
            'special_floors' => [['floor' => 0, 'kind' => 'lobby']],
        ];
    }

    private function building(User $owner): Building
    {
        $b = Building::create(['user_id' => $owner->id, 'name' => 'Torres del Parque', 'config' => $this->config()]);
        $b->unitTypes()->create(['code' => 'A', 'area_m2' => 60, 'bedrooms' => 2, 'bathrooms' => 2]);
        $b->unitTypes()->create(['code' => 'B', 'area_m2' => 45, 'bedrooms' => 1, 'bathrooms' => 1]);
        return $b->fresh();
    }

    private function unit(Building $b, string $code, int $floor = 1): Property
    {
        return Property::factory()->create([
            'user_id'      => $b->user_id,
            'building_id'  => $b->id,
            'unit_type_id' => $b->unitTypes->first()->id,
            'tower'        => 'T1',
            'floor'        => $floor,
            'unit_code'    => $code,
        ]);
    }

    public function test_owner_creates_building_with_unit_types(): void
    {
        $owner = $this->user('landlord');

        $this->actingAs($owner, 'api')->postJson('/api/buildings', [
            'name'       => 'Conjunto Nuevo',
            'config'     => $this->config(),
            'unit_types' => [['code' => 'A', 'area_m2' => 60], ['code' => 'B']],
        ])->assertCreated()->assertJsonPath('data.building.name', 'Conjunto Nuevo')
          ->assertJsonCount(2, 'data.unit_types');
    }

    public function test_layout_must_match_units_per_floor_and_types(): void
    {
        $owner  = $this->user('landlord');
        $config = $this->config();
        $config['towers'][0]['layout'] = ['A', 'Z', 'A'];

        $this->actingAs($owner, 'api')->postJson('/api/buildings', [
            'name' => 'Malo', 'config' => $config, 'unit_types' => [['code' => 'A']],
        ])->assertUnprocessable()->assertJsonValidationErrors('config.towers.0.layout');
    }

    public function test_tenant_cannot_create_buildings(): void
    {
        $this->actingAs($this->user('tenant'), 'api')
            ->postJson('/api/buildings', ['name' => 'X', 'config' => $this->config()])
            ->assertForbidden();
    }

    public function test_owner_sees_private_config_with_tenant_and_arrears(): void
    {
        $owner  = $this->user('landlord');
        $tenant = $this->user('tenant');
        $b      = $this->building($owner);
        $unit   = $this->unit($b, '101');
        $this->unit($b, '102');

        $contract = Contract::create([
            'property_id' => $unit->id, 'landlord_id' => $owner->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subYear(), 'end_date' => now()->addYear(), 'status' => 'active',
        ]);
        Payment::create([
            'contract_id' => $contract->id, 'payment_date' => now()->subDays(75)->toDateString(),
            'amount' => '1000000', 'status' => 'pending', 'payment_method' => 'transfer', 'receipt_path' => '',
        ]);

        $res = $this->actingAs($owner, 'api')->getJson("/api/buildings/{$b->id}/config")->assertOk();

        $res->assertJsonCount(2, 'data.units')
            ->assertJsonPath('data.units.0.unit_code', '101')
            ->assertJsonPath('data.units.0.tenant.id', $tenant->id)
            ->assertJsonPath('data.units.0.commercial_status', 'rented')
            ->assertJsonPath('data.units.0.arrears_bucket', 'gt60')
            ->assertJsonPath('data.units.1.arrears_bucket', 'ok');
        $this->assertStringContainsString('no-store', $res->headers->get('Cache-Control'));
    }

    public function test_other_landlord_cannot_see_building(): void
    {
        $b = $this->building($this->user('landlord'));

        $this->actingAs($this->user('landlord'), 'api')
            ->getJson("/api/buildings/{$b->id}/config")->assertForbidden();
    }

    public function test_index_only_lists_own_buildings_but_admin_sees_all(): void
    {
        $a = $this->user('landlord');
        $this->building($a);
        $this->building($this->user('landlord'));

        $this->actingAs($a, 'api')->getJson('/api/buildings')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->user('admin'), 'api')->getJson('/api/buildings')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_guest_gets_401(): void
    {
        $b = $this->building($this->user('landlord'));
        $this->getJson("/api/buildings/{$b->id}/config")->assertUnauthorized();
    }

    public function test_cache_is_invalidated_when_a_unit_changes(): void
    {
        $owner = $this->user('landlord');
        $b     = $this->building($owner);
        $unit  = $this->unit($b, '101');

        $this->actingAs($owner, 'api')->getJson("/api/buildings/{$b->id}/config")
            ->assertJsonPath('data.units.0.commercial_status', 'available');

        $unit->update(['commercial_status' => 'reserved']);

        $this->actingAs($owner, 'api')->getJson("/api/buildings/{$b->id}/config")
            ->assertJsonPath('data.units.0.commercial_status', 'reserved');
    }

    public function test_public_payload_has_no_private_fields(): void
    {
        $b = $this->building($this->user('landlord'));
        $this->unit($b, '101');

        $unit = app(\App\Services\Visor3d\BuildingConfigService::class)->payload($b, public: true)['units'][0];

        foreach (['tenant', 'contract_id', 'arrears_days', 'arrears_bucket', 'open_requests'] as $field) {
            $this->assertArrayNotHasKey($field, $unit);
        }
    }

    public function test_deleting_building_keeps_units(): void
    {
        $owner = $this->user('landlord');
        $b     = $this->building($owner);
        $unit  = $this->unit($b, '101');

        $this->actingAs($owner, 'api')->deleteJson("/api/buildings/{$b->id}")->assertOk();

        $this->assertNull($unit->fresh()->building_id);
        $this->assertSoftDeleted('buildings', ['id' => $b->id]);
    }
}
