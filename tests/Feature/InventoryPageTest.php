<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_inventory_page_renders_all_eight_blood_groups_statistics_and_sections(): void
    {
        $staffUser = $this->createUserWithRole('staff');

        $response = $this->actingAs($staffUser)->get(route('inventory.index'));

        $response->assertOk();
        $response->assertViewIs('inventory.index');
        $response->assertViewHas('bloodGroups');
        $response->assertViewHas('summary');
        $response->assertViewHas('history');

        $response->assertSee('Blood Inventory Management');
        $response->assertSee('Total Available Units');
        $response->assertSee('Total Blood Groups');
        $response->assertSee('Low Stock Groups');
        $response->assertSee('Critical Stock Groups');
        $response->assertSee('Detailed Blood Inventory');
        $response->assertSee('Recent Inventory History');
        $response->assertSee('Adjust Blood Inventory');

        foreach (['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'] as $group) {
            $response->assertSee($group);
        }

        $response->assertSee('Normal');
        $response->assertSee('Low Stock');
        $response->assertSee('Critical');
    }

    public function test_inventory_page_derives_role_from_authenticated_user_and_ignores_query_override(): void
    {
        $hospitalUser = $this->createUserWithRole('hospital');

        $this->actingAs($hospitalUser)
            ->get(route('inventory.index'))
            ->assertOk()
            ->assertViewHas('activeRole', 'hospital')
            ->assertSee('Regional Stock')
            ->assertDontSee('Adjust Inventory');

        $staffUser = $this->createUserWithRole('staff');

        $this->actingAs($staffUser)
            ->get(route('inventory.index', ['role' => 'hospital']))
            ->assertOk()
            ->assertViewHas('activeRole', 'staff')
            ->assertSee('Adjust Inventory');
    }

    private function createUserWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
