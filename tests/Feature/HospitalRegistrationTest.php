<?php

namespace Tests\Feature;

use App\Models\Donor;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class HospitalRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        RateLimiter::clear($this->registrationThrottleKey());
    }

    public function test_successful_hospital_registration_creates_records_and_assigns_hospital_role(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Dr. Raymond Holt',
            'email' => 'raymond.holt@brooklynhospital.org',
            'organization_name' => 'Brooklyn Memorial Hospital',
            'phone' => '+1 (555) 019-9922',
            'city' => 'New York',
            'address' => '77 Flatbush Avenue',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status');

        $user = User::query()->where('email', 'raymond.holt@brooklynhospital.org')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Dr. Raymond Holt', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->last_login_at);
        $this->assertTrue(Hash::check('hospital-pass-123', $user->password));
        $this->assertSame(['hospital'], $user->roles()->pluck('slug')->all());

        $hospital = Hospital::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^HSP-\d{6}$/', $hospital->hospital_code);
        $this->assertSame('Brooklyn Memorial Hospital', $hospital->name);
        $this->assertSame('77 Flatbush Avenue', $hospital->address);
        $this->assertSame('New York', $hospital->city);
        $this->assertSame('Dr. Raymond Holt', $hospital->primary_contact_name);
        $this->assertSame('+1 (555) 019-9922', $hospital->primary_phone);
        $this->assertSame('raymond.holt@brooklynhospital.org', $hospital->primary_email);

        $hospitalUser = HospitalUser::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($hospital->id, $hospitalUser->hospital_id);
        $this->assertSame('Dr. Raymond Holt', $hospitalUser->contact_name);
        $this->assertSame('+1 (555) 019-9922', $hospitalUser->work_phone);
        $this->assertSame('raymond.holt@brooklynhospital.org', $hospitalUser->work_email);
        $this->assertTrue($hospitalUser->is_primary_contact);
        $this->assertTrue($hospitalUser->is_active);

        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Hospital::query()->count());
        $this->assertSame(1, HospitalUser::query()->count());
        $this->assertSame(0, Donor::query()->count());
    }

    public function test_hospital_starts_with_pending_verification_status_and_active_flag(): void
    {
        $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Admin Jane',
            'email' => 'jane@stjudes.org',
            'organization_name' => 'St. Jude Health Center',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $hospital = Hospital::query()->firstOrFail();

        $this->assertSame('Pending', $hospital->verification_status);
        $this->assertTrue($hospital->is_active);
    }

    public function test_hospital_verification_metadata_remains_null_on_registration(): void
    {
        $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Admin Bob',
            'email' => 'bob@metrohospital.org',
            'organization_name' => 'Metropolitan Hospital',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $hospital = Hospital::query()->firstOrFail();

        $this->assertNull($hospital->verified_by_user_id);
        $this->assertNull($hospital->verified_at);
    }

    public function test_newly_registered_coordinator_authenticates_regenerates_session_and_reaches_dashboard(): void
    {
        $this->get(route('register'))->assertOk();
        $initialSessionId = session()->getId();

        $response = $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Coordinator Sam',
            'email' => 'sam@communityclinic.org',
            'organization_name' => 'Community Care Clinic',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertNotSame($initialSessionId, session()->getId());

        $user = User::query()->where('email', 'sam@communityclinic.org')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $dashboardResponse = $this->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('Hospital Requester Portal');
    }

    public function test_hospital_cannot_create_blood_request_while_pending(): void
    {
        $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Dr. Unverified',
            'email' => 'unverified@urgentcare.org',
            'organization_name' => 'Urgent Care Center',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $user = User::query()->where('email', 'unverified@urgentcare.org')->firstOrFail();

        $response = $this->actingAs($user)->post(route('requests.store'), [
            'blood_group' => 'O+',
            'requested_units' => 2,
            'request_reason' => 'Emergency surgery transfusion needed immediately.',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('blood_requests', 0);
    }

    public function test_client_cannot_submit_existing_hospital_id_or_code_to_attach_to_another_hospital(): void
    {
        $existingHospital = Hospital::factory()->create([
            'hospital_code' => 'HSP-111111',
            'name' => 'Pre-Existing Verified Hospital',
            'verification_status' => 'Verified',
            'is_active' => true,
        ]);

        $response = $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Intruder Coordinator',
            'email' => 'intruder@fakemail.org',
            'organization_name' => 'Attacker Hospital',
            'hospital_id' => $existingHospital->id,
            'hospital_code' => $existingHospital->hospital_code,
            'facility_code' => $existingHospital->hospital_code,
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $response->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('hospitals', 2);

        $user = User::query()->where('email', 'intruder@fakemail.org')->firstOrFail();
        $userHospitalLink = HospitalUser::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertNotSame($existingHospital->id, $userHospitalLink->hospital_id);

        $attackerHospital = Hospital::query()->whereKey($userHospitalLink->hospital_id)->firstOrFail();
        $this->assertSame('Attacker Hospital', $attackerHospital->name);
        $this->assertSame('Pending', $attackerHospital->verification_status);
        $this->assertNotSame($existingHospital->hospital_code, $attackerHospital->hospital_code);
    }

    public function test_client_cannot_forge_verification_status_is_active_or_privileged_roles(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Tamper Hospital Actor',
            'email' => 'tamper-hospital@example.org',
            'organization_name' => 'Tamper Clinic',
            'verification_status' => 'Verified',
            'is_active' => false,
            'roles' => ['admin'],
            'role_id' => 1,
            'verified_by_user_id' => 1,
            'verified_at' => now()->toDateTimeString(),
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $response->assertRedirect(route('dashboard'));

        $user = User::query()->where('email', 'tamper-hospital@example.org')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertSame(['hospital'], $user->roles()->pluck('slug')->all());

        $hospital = Hospital::query()->where('name', 'Tamper Clinic')->firstOrFail();
        $this->assertSame('Pending', $hospital->verification_status);
        $this->assertTrue($hospital->is_active);
        $this->assertNull($hospital->verified_by_user_id);
        $this->assertNull($hospital->verified_at);
    }

    public function test_donor_registration_remains_functional_and_unchanged(): void
    {
        $response = $this->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Donor Preserved',
            'email' => 'donor-preserved@example.org',
            'blood_group' => 'A+',
            'date_of_birth' => '1998-07-21',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertRedirect(route('donor-portal.dashboard'));

        $user = User::query()->where('email', 'donor-preserved@example.org')->firstOrFail();
        $this->assertSame(['donor'], $user->roles()->pluck('slug')->all());

        $donor = Donor::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertFalse($donor->profile_completed);
        $this->assertSame('Pending Review', $donor->eligibility_status);
        $this->assertSame(0, Hospital::query()->count());
    }

    public function test_hospital_registration_failure_rolls_back_all_created_records(): void
    {
        HospitalUser::saving(function (HospitalUser $hu): void {
            if ($hu->work_email === 'rollback-hospital@example.org') {
                throw new \RuntimeException('Simulated hospital user creation crash.');
            }
        });

        try {
            $response = $this->post(route('register.attempt'), [
                'role' => 'hospital',
                'name' => 'Rollback Coordinator',
                'email' => 'rollback-hospital@example.org',
                'organization_name' => 'Failing Hospital',
                'password' => 'hospital-pass-123',
                'password_confirmation' => 'hospital-pass-123',
            ]);

            $response->assertStatus(500);
            $this->assertGuest();
            $this->assertDatabaseMissing('users', ['email' => 'rollback-hospital@example.org']);
            $this->assertDatabaseMissing('hospitals', ['name' => 'Failing Hospital']);
            $this->assertDatabaseCount('hospital_users', 0);
            $this->assertDatabaseCount('role_user', 0);
        } finally {
            HospitalUser::flushEventListeners();
        }
    }

    public function test_hospital_registration_safely_handles_duplicate_email_and_unique_constraints(): void
    {
        User::factory()->create(['email' => 'existing-coordinator@example.org']);

        $responseExistingUser = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Duplicate Attempt',
            'email' => '  EXISTING-COORDINATOR@example.org  ',
            'organization_name' => 'Duplicate Hospital',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $responseExistingUser->assertRedirect(route('register'));
        $responseExistingUser->assertSessionHasErrors('email');
        $this->assertSame(
            'Unable to complete registration with this email address. Please sign in or contact Blood Bank Staff for assistance.',
            session('errors')->first('email')
        );
        $this->assertGuest();

        Donor::factory()->create([
            'user_id' => null,
            'email' => 'walkin-donor-collision@example.org',
        ]);

        $responseWalkIn = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'WalkIn Duplicate',
            'email' => 'walkin-donor-collision@example.org',
            'organization_name' => 'Collision Clinic',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $responseWalkIn->assertRedirect(route('register'));
        $responseWalkIn->assertSessionHasErrors('email');
        $this->assertSame(
            'Unable to complete registration with this email address. Please sign in or contact Blood Bank Staff for assistance.',
            session('errors')->first('email')
        );
        $this->assertGuest();

        User::saving(function (User $user): void {
            if ($user->email === 'concurrent-hosp-race@example.org' && User::query()->where('email', 'concurrent-hosp-race@example.org')->doesntExist()) {
                DB::table('users')->insert([
                    'name' => 'Concurrent Winner',
                    'email' => 'concurrent-hosp-race@example.org',
                    'password' => 'secret-hashed',
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        try {
            $responseRace = $this->from(route('register'))->post(route('register.attempt'), [
                'role' => 'hospital',
                'name' => 'Concurrent Hospital Loser',
                'email' => 'concurrent-hosp-race@example.org',
                'organization_name' => 'Concurrent Clinic',
                'password' => 'hospital-pass-123',
                'password_confirmation' => 'hospital-pass-123',
            ]);

            $responseRace->assertRedirect(route('register'));
            $responseRace->assertSessionHasErrors('email');
            $this->assertSame(
                'Unable to complete registration with this email address. Please sign in or contact Blood Bank Staff for assistance.',
                session('errors')->first('email')
            );
            $this->assertGuest();
        } finally {
            User::flushEventListeners();
        }
    }

    public function test_hospital_registration_rate_limiting_works(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from(route('register'))->post(route('register.attempt'), [
                'role' => 'hospital',
                'name' => "Rate Limit Hosp {$attempt}",
                'email' => "rate-hosp-{$attempt}@example.org",
                'organization_name' => 'Rate Limit Hospital',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])->assertSessionHasErrors('password');
        }

        $throttled = $this->from(route('register'))->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Blocked Sixth Attempt',
            'email' => 'rate-hosp-6@example.org',
            'organization_name' => 'Blocked Hospital',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $throttled->assertRedirect(route('register'));
        $throttled->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many registration attempts', session('errors')->first('email'));
        $this->assertGuest();

        RateLimiter::clear($this->registrationThrottleKey());
    }

    public function test_inactive_tab_fields_do_not_cause_validation_failures_or_unexpected_writes(): void
    {
        $responseHosp = $this->post(route('register.attempt'), [
            'role' => 'hospital',
            'name' => 'Clean Hospital Coordinator',
            'email' => 'clean-hospital@example.org',
            'organization_name' => 'Clean Hospital Facility',
            'blood_group' => 'O+',
            'date_of_birth' => '1990-01-01',
            'password' => 'hospital-pass-123',
            'password_confirmation' => 'hospital-pass-123',
        ]);

        $responseHosp->assertRedirect(route('dashboard'));
        $this->assertSame(0, Donor::query()->count());
        $this->assertSame(1, Hospital::query()->count());

        auth()->logout();

        $responseDonor = $this->post(route('register.attempt'), [
            'role' => 'donor',
            'name' => 'Clean Donor',
            'email' => 'clean-donor@example.org',
            'blood_group' => 'B+',
            'organization_name' => 'Stale Hospital Name From Form',
            'address' => 'Stale Address',
            'city' => 'Stale City',
            'phone' => '123456789',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $responseDonor->assertRedirect(route('donor-portal.dashboard'));
        $this->assertSame(1, Donor::query()->count());
        $this->assertSame(1, Hospital::query()->count());
    }

    private function registrationThrottleKey(): string
    {
        return 'register|'.Str::transliterate('127.0.0.1');
    }
}
