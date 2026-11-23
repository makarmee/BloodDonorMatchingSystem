<?php

namespace Tests\Feature;

use App\Models\Donor;
use App\Models\Hospital;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('register|127.0.0.1');
    }

    public function test_registration_page_loads_successfully(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertViewIs('auth.register');
        $response->assertSee(route('register.attempt'), false);
    }

    public function test_valid_donor_registration_creates_user_with_hashed_password_and_authenticates(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'John Donor',
            'email' => 'john.donor@example.com',
            'blood_group' => 'O+',
            'date_of_birth' => '1995-03-20',
            'password' => 'secure-password-123',
            'password_confirmation' => 'secure-password-123',
        ]);

        $response->assertRedirect(route('donor-portal.dashboard'));

        $user = User::query()->where('email', 'john.donor@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('John Donor', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('secure-password-123', $user->password));
        $this->assertSame(['donor'], $user->roles()->pluck('slug')->all());

        $donor = Donor::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('john.donor@example.com', $donor->email);
        $this->assertSame('O+', $donor->blood_group);
    }

    public function test_valid_hospital_registration_creates_user_with_hashed_password_and_authenticates(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Dr. Lisa Cuddy',
            'email' => 'lisa.cuddy@princeton-plainsboro.org',
            'organization_name' => 'Princeton Plainsboro Teaching Hospital',
            'phone' => '+1 (555) 012-3456',
            'city' => 'Princeton',
            'address' => '100 Medical Center Drive',
            'password' => 'hospital-secure-123',
            'password_confirmation' => 'hospital-secure-123',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'lisa.cuddy@princeton-plainsboro.org')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Dr. Lisa Cuddy', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('hospital-secure-123', $user->password));
        $this->assertSame(['hospital'], $user->roles()->pluck('slug')->all());

        $hospital = Hospital::query()->where('primary_email', 'lisa.cuddy@princeton-plainsboro.org')->firstOrFail();
        $this->assertSame('Princeton Plainsboro Teaching Hospital', $hospital->name);
    }

    public function test_successful_registration_redirects_to_a_valid_page_with_ok_status(): void
    {
        $response = $this->followingRedirects()->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Redirect Verification Donor',
            'email' => 'redirect.donor@example.com',
            'blood_group' => 'A+',
            'date_of_birth' => '1992-07-14',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();
        $response->assertViewIs('donor-portal.dashboard');

        auth()->logout();

        $hospitalResponse = $this->followingRedirects()->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Dr. James Wilson',
            'email' => 'james.wilson@oncology.org',
            'organization_name' => 'Mercy General Hospital',
            'phone' => '+1 (555) 456-7890',
            'city' => 'Philadelphia',
            'address' => '200 Hope Way',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $hospitalResponse->assertOk();
        $hospitalResponse->assertViewIs('dashboard.index');
    }

    public function test_registration_succeeds_even_when_roles_have_not_been_pre_seeded(): void
    {
        $this->assertSame(0, Role::query()->count());

        $response = $this->followingRedirects()->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Unseeded DB Donor',
            'email' => 'unseeded.donor@example.com',
            'blood_group' => 'B+',
            'date_of_birth' => '1990-01-01',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('roles', ['slug' => 'donor']);
        $this->assertDatabaseHas('users', ['email' => 'unseeded.donor@example.com']);
    }

    public function test_invalid_registration_data_produces_validation_errors(): void
    {
        $response = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'invalid_role',
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors(['role', 'name', 'email', 'password']);
        $this->assertGuest();
        $this->assertSame(0, User::query()->count());
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Duplicate Attempt',
            'email' => 'existing@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertSame(1, User::query()->count());
    }

    public function test_registration_works_with_persian_locale(): void
    {
        $response = $this->withSession(['locale' => 'fa'])
            ->followingRedirects()
            ->post(route('register.attempt'), [
                'role' => 'donor',
                'name' => 'اهداکننده فارسی',
                'email' => 'persian.donor@example.com',
                'blood_group' => 'AB+',
                'date_of_birth' => '1993-04-10',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $this->assertSame('fa', session('locale'));
        $this->assertAuthenticated();
    }

    public function test_registration_works_with_english_locale(): void
    {
        $response = $this->withSession(['locale' => 'en'])
            ->followingRedirects()
            ->post(route('register.attempt'), [
                'role' => 'donor',
                'name' => 'English Donor',
                'email' => 'english.donor@example.com',
                'blood_group' => 'O-',
                'date_of_birth' => '1996-08-25',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

        $response->assertOk();
        $response->assertSee('dir="ltr"', false);
        $this->assertSame('en', session('locale'));
        $this->assertAuthenticated();
    }
}
