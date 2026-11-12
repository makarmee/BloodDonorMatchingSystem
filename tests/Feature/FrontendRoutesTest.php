<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_home_page_renders_bdms_landing_view(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Current Blood Group Availability');
        $response->assertSee('Register as Donor');
    }

    public function test_authentication_pages_render_successfully_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in to BDMS')
            ->assertDontSee('Quick Frontend Role Previews');

        $this->get(route('register'))
            ->assertOk()
            ->assertSee('Create your BDMS account');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Forgot your password?');
    }

    public function test_dashboard_requires_authentication_and_renders_authenticated_users_role_portal(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $staffUser = $this->createUserWithRole('staff');
        $this->actingAs($staffUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Blood Bank Operations Dashboard')
            ->assertDontSee('?role=');

        $hospitalUser = $this->createUserWithRole('hospital');
        $this->actingAs($hospitalUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hospital Requester Portal');

        $labOfficerUser = $this->createUserWithRole('lab_officer');
        $this->actingAs($labOfficerUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Laboratory Screening Dashboard');

        $donorUser = $this->createUserWithRole('donor');
        $this->actingAs($donorUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Donor Portal Overview');

        $this->actingAs($staffUser)
            ->get(route('dashboard', ['role' => 'hospital']))
            ->assertOk()
            ->assertSee('Blood Bank Operations Dashboard')
            ->assertDontSee('Hospital Requester Portal');
    }

    private function createUserWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
