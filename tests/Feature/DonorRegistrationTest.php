<?php

namespace Tests\Feature;

use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DonorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear($this->registrationThrottleKey());
    }

    public function test_guest_can_view_registration_page_wired_to_real_endpoint(): void
    {
        $response = $this->get(route('register'));

        $response->assertOk();
        $response->assertViewIs('auth.register');
        $response->assertSee(route('register.attempt'), false);
        $response->assertDontSee('Frontend UI mode');
    }

    public function test_authenticated_user_cannot_submit_registration_endpoint(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Ignored Registration',
            'email' => 'ignored@example.org',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donors', 0);
    }

    public function test_donor_self_registration_creates_user_donor_eligibility_record_and_logs_in(): void
    {
        $this->get(route('register'))->assertOk();
        $initialSessionId = session()->getId();

        $response = $this->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => '  Alice Bennett  ',
            'email' => '  Alice.Bennett@Example.ORG  ',
            'blood_group' => 'O+',
            'date_of_birth' => '1994-05-12',
            'organization_name' => 'Stale Hospital Input',
            'facility_code' => 'HSP-9999',
            'password' => 'secret-pass-99',
            'password_confirmation' => 'secret-pass-99',
        ]);

        $response->assertRedirect(route('donor-portal.dashboard'));
        $response->assertSessionHas('status');
        $this->assertNotSame($initialSessionId, session()->getId());

        $user = User::query()->where('email', 'alice.bennett@example.org')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Alice Bennett', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->last_login_at);
        $this->assertTrue(Hash::check('secret-pass-99', $user->password));
        $this->assertSame(['donor'], $user->roles()->pluck('slug')->all());

        $donor = Donor::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertMatchesRegularExpression('/^DNR-\d{6}$/', $donor->donor_code);
        $this->assertSame('Alice Bennett', $donor->full_name);
        $this->assertSame('alice.bennett@example.org', $donor->email);
        $this->assertSame('O+', $donor->blood_group);
        $this->assertSame('1994-05-12', $donor->date_of_birth?->toDateString());
        $this->assertSame('Available', $donor->availability_status);
        $this->assertSame('Active', $donor->account_status);
        $this->assertFalse($donor->profile_completed);
        $this->assertSame('Pending Review', $donor->eligibility_status);

        $eligibilityRecord = DonorEligibilityRecord::query()
            ->where('donor_id', $donor->id)
            ->firstOrFail();

        $this->assertTrue($eligibilityRecord->is_current);
        $this->assertSame('Pending Review', $eligibilityRecord->eligibility_status);
        $this->assertNull($eligibilityRecord->reviewed_by_user_id);
        $this->assertSame('Donor Self-Registration', $eligibilityRecord->clearance_source);

        $this->get(route('donor-portal.dashboard'))->assertOk();
    }

    public function test_donor_self_registration_succeeds_without_optional_blood_group_and_date_of_birth(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Minimal Donor',
            'email' => 'minimal@example.org',
            'blood_group' => '',
            'date_of_birth' => '',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('donor-portal.dashboard'));

        $donor = Donor::query()->where('email', 'minimal@example.org')->firstOrFail();

        $this->assertNull($donor->blood_group);
        $this->assertNull($donor->date_of_birth);
        $this->assertFalse($donor->profile_completed);
        $this->assertSame('Pending Review', $donor->eligibility_status);
        $this->assertSame(1, DonorEligibilityRecord::query()->where('donor_id', $donor->id)->count());
    }

    public function test_donor_self_registration_ignores_forged_privileged_and_clinical_fields(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Tamper Test',
            'email' => 'tamper@example.org',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'is_active' => false,
            'profile_completed' => true,
            'eligibility_status' => 'Eligible',
            'donor_code' => 'DNR-999999',
            'account_status' => 'Inactive',
            'availability_status' => 'Inactive',
            'user_id' => 9999,
        ]);

        $response->assertRedirect(route('donor-portal.dashboard'));

        $user = User::query()->where('email', 'tamper@example.org')->firstOrFail();
        $donor = Donor::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertTrue($user->is_active);
        $this->assertSame(['donor'], $user->roles()->pluck('slug')->all());
        $this->assertNotSame('DNR-999999', $donor->donor_code);
        $this->assertFalse($donor->profile_completed);
        $this->assertSame('Pending Review', $donor->eligibility_status);
        $this->assertSame('Active', $donor->account_status);
        $this->assertSame('Available', $donor->availability_status);
        $this->assertSame('Pending Review', $donor->currentEligibilityRecord?->eligibility_status);
    }

    #[TestWith(['admin'])]
    #[TestWith(['staff'])]
    #[TestWith(['lab_officer'])]
    #[TestWith(['hospital'])]
    #[TestWith(['unknown_role'])]
    public function test_donor_self_registration_rejects_disallowed_or_unimplemented_roles(string $role): void
    {
        $response = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => $role,
            'name' => 'Role Attempt',
            'email' => 'role-attempt@example.org',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('register'));
        $response->assertSessionHasErrors('role');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('donors', 0);
    }

    public function test_donor_self_registration_rejects_duplicate_user_and_walk_in_donor_emails_identically(): void
    {
        User::factory()->create(['email' => 'existing-user@example.org']);

        $walkInDonor = Donor::factory()->create([
            'user_id' => null,
            'email' => 'walkin-donor@example.org',
            'full_name' => 'Original Walk-In Donor',
        ]);

        $existingUserResponse = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Duplicate User',
            'email' => '  EXISTING-USER@Example.ORG  ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $existingUserResponse->assertRedirect(route('register'));
        $existingUserResponse->assertSessionHasErrors('email');
        $userCollisionError = session('errors')->first('email');
        $this->assertGuest();

        $walkInResponse = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Attempted Claim',
            'email' => '  WALKIN-DONOR@Example.ORG  ',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $walkInResponse->assertRedirect(route('register'));
        $walkInResponse->assertSessionHasErrors('email');
        $this->assertSame($userCollisionError, session('errors')->first('email'));
        $this->assertGuest();

        $walkInDonor->refresh();
        $this->assertNull($walkInDonor->user_id);
        $this->assertSame('Original Walk-In Donor', $walkInDonor->full_name);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('donors', 1);
    }

    public function test_donor_self_registration_validates_blood_group_age_bounds_and_password(): void
    {
        $tooYoungDob = CarbonImmutable::today()->subYears(15)->toDateString();
        $tooOldDob = CarbonImmutable::today()->subYears(111)->toDateString();

        $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Invalid Fields',
            'email' => 'invalid-fields@example.org',
            'blood_group' => 'Z+',
            'date_of_birth' => $tooYoungDob,
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['blood_group', 'date_of_birth', 'password']);

        $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Over Max Age',
            'email' => 'over-age@example.org',
            'date_of_birth' => $tooOldDob,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['date_of_birth']);

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('donors', 0);
    }

    public function test_donor_self_registration_rate_limits_repeated_attempts(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from(route('register'))->post(route('register.attempt'), [
                'role' => 'donor',
                'name' => "Rate Limit Check {$attempt}",
                'email' => "ratelimit-{$attempt}@example.org",
                'password' => 'short',
                'password_confirmation' => 'short',
            ])->assertSessionHasErrors('password');
        }

        $throttledResponse = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Blocked Sixth Attempt',
            'email' => 'ratelimit-6@example.org',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $throttledResponse->assertRedirect(route('register'));
        $throttledResponse->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many registration attempts', session('errors')->first('email'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);

        RateLimiter::clear($this->registrationThrottleKey());
    }

    private function registrationThrottleKey(): string
    {
        return 'register|'.Str::transliterate('127.0.0.1');
    }
}
