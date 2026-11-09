<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnitsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): void
    {
        Sanctum::actingAs(User::factory()->create());
    }

    private function building(array $overrides = []): Building
    {
        return Building::create(array_merge(['name' => 'برج النيل', 'address' => 'المعادي، القاهرة'], $overrides));
    }

    private function input(Building $building, array $overrides = []): array
    {
        return array_merge(['building_id' => $building->id, 'number' => '١٠١', 'floor' => 1, 'type' => 'apartment'], $overrides);
    }

    public function test_all_unit_endpoints_require_authentication(): void
    {
        $this->getJson('/api/units')->assertUnauthorized();
        $this->getJson('/api/units/1')->assertUnauthorized();
        $this->postJson('/api/units')->assertUnauthorized();
        $this->putJson('/api/units/1')->assertUnauthorized();
        $this->getJson('/api/building-options')->assertUnauthorized();
    }

    public function test_create_view_update_and_building_counts(): void
    {
        $this->owner();
        $building = $this->building();
        $response = $this->postJson('/api/units', $this->input($building, [
            'area' => '125.50', 'asking_sale_price' => '2500000.25', 'asking_rent_price' => '12000.50', 'notes' => 'واجهة بحرية',
        ]))->assertCreated()->assertJsonPath('data.number', '101')->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.area', '125.50')->assertJsonPath('data.asking_sale_price', '2500000.25')
            ->assertJsonPath('data.asking_rent_price', '12000.50')->assertJsonPath('data.building.name', 'برج النيل');
        $id = $response->json('data.id');
        $this->getJson('/api/units/'.$id)->assertOk()->assertJsonPath('data.notes', 'واجهة بحرية');
        $this->putJson('/api/units/'.$id, $this->input($building, ['number' => '١٠٢', 'type' => 'office', 'asking_sale_price' => '0.00']))
            ->assertOk()->assertJsonPath('data.number', '102')->assertJsonPath('data.type', 'office')->assertJsonPath('data.asking_sale_price', '0.00');
        $this->getJson('/api/buildings/'.$building->id)->assertJsonPath('data.units_count', 1)->assertJsonPath('data.status_counts.available', 1);
        $this->getJson('/api/buildings/'.$building->id.'/units')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/units/999999')->assertNotFound();
    }

    public function test_duplicates_are_normalized_and_scoped_to_the_building(): void
    {
        $this->owner();
        $a = $this->building();
        $b = $this->building(['name' => 'برج آخر']);
        $response = $this->postJson('/api/units', $this->input($a))->assertCreated();
        foreach (['101', ' ١٠١ ', '۱۰۱'] as $number) {
            $this->postJson('/api/units', $this->input($a, ['number' => $number]))->assertUnprocessable()->assertJsonValidationErrors('number');
        }
        $this->postJson('/api/units', $this->input($b))->assertCreated();
        $this->putJson('/api/units/'.$response->json('data.id'), $this->input($a))->assertOk();
        $this->putJson('/api/units/'.$response->json('data.id'), $this->input($b))->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->assertDatabaseCount('units', 2);
    }

    public function test_validation_and_status_cannot_be_manually_changed(): void
    {
        $this->owner();
        $building = $this->building();
        $this->postJson('/api/units', [])->assertUnprocessable()->assertJsonValidationErrors(['building_id', 'number', 'floor', 'type']);
        foreach ([['area' => 0], ['floor' => 1.5], ['asking_sale_price' => -1], ['asking_rent_price' => '12.345'], ['type' => 'bad'], ['status' => 'sold'], ['building_id' => 999999], ['number' => '   ']] as $invalid) {
            $this->postJson('/api/units', $this->input($building, $invalid))->assertUnprocessable();
        }
        $response = $this->postJson('/api/units', $this->input($building, ['floor' => -1, 'type' => 'other', 'status' => null]))->assertCreated()->assertJsonPath('data.status', 'available');
        $this->putJson('/api/units/'.$response->json('data.id'), $this->input($building, ['status' => 'rented']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('units', ['id' => $response->json('data.id'), 'status' => 'available']);
    }

    public function test_inactive_buildings_and_non_available_units_are_protected(): void
    {
        $this->owner();
        $active = $this->building();
        $inactive = $this->building(['is_active' => false]);
        $this->postJson('/api/units', $this->input($inactive))->assertUnprocessable()->assertJsonValidationErrors('building_id');
        $unit = Unit::create($this->input($active, ['status' => 'sold', 'area' => '100.00']));
        $this->putJson('/api/units/'.$unit->id, $this->input($active, ['number' => '102']))->assertUnprocessable()->assertJsonValidationErrors('number');
        $this->putJson('/api/units/'.$unit->id, $this->input($active, ['area' => 100, 'asking_sale_price' => '999.99']))->assertOk()->assertJsonPath('data.status', 'sold');
        $unit->update(['status' => 'available']);
        $this->putJson('/api/units/'.$unit->id, $this->input($inactive))->assertUnprocessable()->assertJsonValidationErrors('building_id');
        $active->update(['is_active' => false]);
        $this->putJson('/api/units/'.$unit->id, $this->input($active, ['notes' => 'تعديل محفوظ']))->assertOk();
    }

    public function test_search_filters_pagination_and_options(): void
    {
        $this->owner();
        $a = $this->building();
        $b = $this->building(['name' => 'برج البحر']);
        Unit::create($this->input($a, ['status' => 'rented', 'type' => 'shop']));
        for ($i = 0; $i < 22; $i++) {
            Unit::create($this->input($b, ['number' => (string) $i]));
        }
        $this->getJson('/api/units')->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23);
        $this->getJson('/api/units?page=2')->assertJsonCount(3, 'data');
        $this->getJson('/api/units?building_id='.$a->id.'&type=shop&status=rented')->assertJsonCount(1, 'data')->assertJsonPath('data.0.building_id', $a->id);
        $this->getJson('/api/units?search='.urlencode('النيل'))->assertJsonCount(1, 'data');
        $this->getJson('/api/units?search='.urlencode('١٠١'))->assertJsonCount(1, 'data');
        $this->getJson('/api/units?status=sold')->assertJsonCount(0, 'data');
        $this->getJson('/api/units?type=bad')->assertUnprocessable();
        $this->getJson('/api/units?building_id=999999')->assertUnprocessable();
        $this->getJson('/api/building-options')->assertOk()->assertJsonCount(2, 'data');
    }
}
