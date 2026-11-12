<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_can_view_login_page_without_prototype_role_previews(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertViewIs('auth.login');
        $response->assertSee(route('login.attempt'), false);
        $response->assertDontSee('Quick Frontend Role Previews');
        $response->assertDontSee('?role=');
    }

    #[TestWith(['admin', 'dashboard'])]
    #[TestWith(['staff', 'dashboard'])]
    #[TestWith(['lab_officer', 'dashboard'])]
    #[TestWith(['hospital', 'dashboard'])]
    #[TestWith(['donor', 'donor-portal.dashboard'])]
    public function test_valid_login_authenticates_active_user_updates_last_login_and_redirects_by_role(
        string $roleSlug,
        string $expectedRoute,
    ): void {
        $user = $this->createUserWithRole($roleSlug, ['is_active' => true, 'last_login_at' => null]);

        $response = $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route($expectedRoute));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
    }

    public function test_login_regenerates_session_id(): void
    {
        $user = $this->createUserWithRole('staff');

        $this->get(route('login'))->assertOk();
        $initialSessionId = session()->getId();

        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertNotSame($initialSessionId, session()->getId());
    }

    public function test_login_rejects_invalid_credentials_without_leaking_email_existence(): void
    {
        $user = $this->createUserWithRole('staff');

        $wrongPasswordResponse = $this->from(route('login'))->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $wrongPasswordResponse->assertRedirect(route('login'));
        $wrongPasswordResponse->assertSessionHasErrors('email');
        $this->assertGuest();
        $wrongPasswordError = session('errors')->first('email');

        $unknownEmailResponse = $this->from(route('login'))->post(route('login.attempt'), [
            'email' => 'nonexistent@example.org',
            'password' => 'wrong-password',
        ]);

        $unknownEmailResponse->assertRedirect(route('login'));
        $unknownEmailResponse->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->assertSame($wrongPasswordError, session('errors')->first('email'));
    }

    public function test_inactive_user_cannot_log_in_and_receives_generic_error(): void
    {
        $inactiveUser = $this->createUserWithRole('staff', ['is_active' => false]);

        $response = $this->from(route('login'))->post(route('login.attempt'), [
            'email' => $inactiveUser->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertSame('These credentials do not match our records.', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_login_rate_limits_repeated_failed_attempts(): void
    {
        $user = $this->createUserWithRole('staff');
        $throttleKey = Str::transliterate(Str::lower($user->email).'|127.0.0.1');
        RateLimiter::clear($throttleKey);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from(route('login'))->post(route('login.attempt'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('email');
        }

        $throttledResponse = $this->from(route('login'))->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $throttledResponse->assertRedirect(route('login'));
        $throttledResponse->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));
        $this->assertGuest();

        RateLimiter::clear($throttleKey);
    }

    #[TestWith(['staff', 'dashboard'])]
    #[TestWith(['hospital', 'dashboard'])]
    #[TestWith(['donor', 'donor-portal.dashboard'])]
    public function test_authenticated_users_are_redirected_away_from_guest_auth_pages(
        string $roleSlug,
        string $expectedRoute,
    ): void {
        $user = $this->createUserWithRole($roleSlug);

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect(route($expectedRoute));

        $this->actingAs($user)
            ->get(route('register'))
            ->assertRedirect(route($expectedRoute));

        $this->actingAs($user)
            ->get(route('password.request'))
            ->assertRedirect(route($expectedRoute));
    }

    public function test_logout_logs_user_out_invalidates_session_and_regenerates_csrf_token(): void
    {
        $user = $this->createUserWithRole('staff');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $initialSessionId = session()->getId();
        $initialToken = csrf_token();

        $response = $this->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($initialSessionId, session()->getId());
        $this->assertNotSame($initialToken, csrf_token());

        $this->get('/logout')->assertMethodNotAllowed();
    }

    public function test_unauthenticated_guests_are_redirected_to_login_on_protected_routes(): void
    {
        $protectedGetRoutes = [
            route('dashboard'),
            route('inventory.index'),
            route('donors.index'),
            route('donors.create'),
            route('donations.index'),
            route('donations.create'),
            route('screening.index'),
            route('screening.create'),
            route('requests.index'),
            route('requests.create'),
            route('camps.index'),
            route('camps.create'),
            route('donor-portal.dashboard'),
            route('donor-portal.appointments.index'),
            route('donor-portal.appointments.create'),
            route('donor-portal.history'),
            route('donor-portal.eligibility'),
        ];

        foreach ($protectedGetRoutes as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->post(route('requests.store'), [])->assertRedirect(route('login'));
    }

    #[TestWith(['admin'])]
    #[TestWith(['staff'])]
    #[TestWith(['lab_officer'])]
    #[TestWith(['hospital'])]
    public function test_non_donor_roles_receive_forbidden_on_donor_portal_routes(string $roleSlug): void
    {
        $user = $this->createUserWithRole($roleSlug);

        foreach ([
            route('donor-portal.dashboard'),
            route('donor-portal.appointments.index'),
            route('donor-portal.appointments.create'),
            route('donor-portal.history'),
            route('donor-portal.eligibility'),
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    public function test_inactive_donor_receives_forbidden_on_donor_portal_routes(): void
    {
        $inactiveDonor = $this->createUserWithRole('donor', ['is_active' => false]);

        $this->actingAs($inactiveDonor)
            ->get(route('donor-portal.dashboard'))
            ->assertForbidden();
    }

    public function test_public_routes_remain_accessible_without_authentication(): void
    {
        $this->get(route('home'))->assertOk();
        $this->get(route('public.stock'))->assertOk();
        $this->get(route('public.camps.index'))->assertOk();
    }

    public function test_navigation_renders_authenticated_users_role_and_strips_fake_previews_and_alerts(): void
    {
        $admin = $this->createUserWithRole('admin', ['name' => 'Amina Admin']);
        $adminResponse = $this->actingAs($admin)->get(route('dashboard'));
        $adminResponse->assertOk()
            ->assertSee('Amina Admin')
            ->assertSee('Administrator')
            ->assertSee('Blood Inventory')
            ->assertSee('Lab Screening')
            ->assertSee('Blood Requests')
            ->assertSee(route('logout'), false)
            ->assertDontSee('?role=')
            ->assertDontSee('Urgent Alerts')
            ->assertDontSee('Preview Account')
            ->assertDontSee('Dr. Maya Lin');

        $staff = $this->createUserWithRole('staff', ['name' => 'Samir Staff']);
        $staffResponse = $this->actingAs($staff)->get(route('dashboard'));
        $staffResponse->assertOk()
            ->assertSee('Samir Staff')
            ->assertSee('Blood Bank Staff')
            ->assertSee('Blood Inventory')
            ->assertSee('Blood Requests')
            ->assertDontSee('Lab Screening')
            ->assertDontSee(route('screening.index'), false)
            ->assertDontSee('?role=');

        $labOfficer = $this->createUserWithRole('lab_officer', ['name' => 'Laila Lab']);
        $labResponse = $this->actingAs($labOfficer)->get(route('dashboard'));
        $labResponse->assertOk()
            ->assertSee('Laila Lab')
            ->assertSee('Lab Officer')
            ->assertSee('Lab Screening')
            ->assertSee('Donors')
            ->assertDontSee(route('inventory.index'), false)
            ->assertDontSee(route('requests.index'), false)
            ->assertDontSee('?role=');

        $hospital = $this->createUserWithRole('hospital', ['name' => 'Hana Hospital']);
        $hospitalResponse = $this->actingAs($hospital)->get(route('dashboard'));
        $hospitalResponse->assertOk()
            ->assertSee('Hana Hospital')
            ->assertSee('Hospital Partner')
            ->assertSee('Request Blood Units')
            ->assertSee('Track Requests')
            ->assertSee('Regional Stock')
            ->assertDontSee('Donations Log')
            ->assertDontSee('Lab Screening')
            ->assertDontSee(route('screening.index'), false)
            ->assertDontSee('?role=');

        $donor = $this->createUserWithRole('donor', ['name' => 'Dani Donor']);
        $donorResponse = $this->actingAs($donor)->get(route('donor-portal.dashboard'));
        $donorResponse->assertOk()
            ->assertSee('Dani Donor')
            ->assertSee('Donor Portal')
            ->assertSee('My Appointments')
            ->assertSee('Donation History')
            ->assertSee('Eligibility')
            ->assertSee(route('logout'), false)
            ->assertDontSee(route('inventory.index'), false)
            ->assertDontSee(route('screening.index'), false)
            ->assertDontSee('?role=');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUserWithRole(string $roleSlug, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
