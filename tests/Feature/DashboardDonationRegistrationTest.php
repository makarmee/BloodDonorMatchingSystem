<?php

namespace Tests\Feature;

use App\Models\BloodComponent;
use App\Models\BloodUnit;
use App\Models\Donation;
use App\Models\DonationCamp;
use App\Models\DonationCenter;
use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\Role;
use App\Models\ScreeningRecord;
use App\Models\User;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDonationRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(BloodComponentSeeder::class);
        $this->seed(ScreeningTestTypeSeeder::class);
        $this->seed(ComponentScreeningRequirementSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Admin Alex', 'email' => 'admin.alex@example.com']);
        $this->admin->roles()->attach(Role::where('slug', 'admin')->firstOrFail());

        $this->staff = User::factory()->create(['name' => 'Staff Sarah', 'email' => 'staff.sarah@example.com']);
        $this->staff->roles()->attach(Role::where('slug', 'staff')->firstOrFail());
    }

    public function test_admin_dashboard_renders_record_donation_button_and_modal_form(): void
    {
        $eligibleDonor = Donor::factory()->eligible()->create([
            'full_name' => 'Farhad Karimi',
            'blood_group' => 'O+',
        ]);
        DonorEligibilityRecord::factory()->eligible()->for($eligibleDonor)->create();

        $ineligibleDonor = Donor::factory()->notEligible()->create([
            'full_name' => 'Sara Ahmadi',
            'blood_group' => 'A-',
        ]);
        DonorEligibilityRecord::factory()->notEligible()->for($ineligibleDonor)->create();

        $response = $this->actingAs($this->admin)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('record-donation-modal');
        $response->assertSee(__('Record Donation'));
        $response->assertSee(__('Register Blood Donation'));
        $response->assertSee(__('Select Registered Donor'));
        $response->assertSee('Farhad Karimi');
        $response->assertSee('Sara Ahmadi');
        $response->assertSee(__('Fixed Donation Center'));
        $response->assertSee(__('Mobile Donation Camp'));
        $response->assertSee(__('Blood Component'));
        $response->assertSee(__('Quantity (ml)'));
        $response->assertSee(__('Collection Status'));
        $response->assertSee(__('Storage Location'));
    }

    public function test_admin_can_successfully_register_completed_donation_from_dashboard(): void
    {
        $donor = Donor::factory()->eligible()->create([
            'blood_group' => 'B+',
            'full_name' => 'Reza Mohammadi',
        ]);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();

        $component = BloodComponent::where('code', 'WB-450')->orWhere('code', 'WB')->firstOrFail();

        $center = DonationCenter::firstOrCreate(
            ['center_code' => 'CTR-TEST-01'],
            [
                'name' => 'Central Blood Center',
                'address' => '100 Main St',
                'city' => 'Metropolis',
                'operating_hours' => '08:00 - 18:00',
                'default_slot_capacity' => 10,
                'is_active' => true,
            ]
        );

        $payload = [
            'redirect_to' => 'dashboard',
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_time' => '14:30',
            'blood_component_id' => $component->id,
            'center_id' => $center->id,
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'storage_location' => 'Quarantine Shelf A-3',
            'staff_member' => 'Admin Alex',
            'collection_notes' => 'Registration via Admin Dashboard modal.',
        ];

        $response = $this->actingAs($this->admin)->post(route('donations.store'), $payload);

        $donation = Donation::where('donor_id', $donor->id)->latest()->first();
        $this->assertNotNull($donation, 'Donation record should be created.');

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('success', "Donation {$donation->donation_code} registered successfully.");

        $this->assertSame('Completed', $donation->collection_status);
        $this->assertSame(450, (int) $donation->quantity_ml);
        $this->assertSame('B+', $donation->blood_group);
        $this->assertSame($center->id, $donation->center_id);
        $this->assertSame('Admin Alex', $donation->staff_member_name);

        // Verify BloodUnit auto-creation in Quarantined status
        $unit = BloodUnit::where('donation_id', $donation->id)->first();
        $this->assertNotNull($unit, 'Blood unit should be automatically generated for completed collection.');
        $this->assertSame('Quarantined', $unit->unit_status);
        $this->assertSame('Quarantine Shelf A-3', $unit->storage_location);
        $this->assertSame('B+', $unit->blood_group);

        // Verify ScreeningRecord initialization
        $screeningsCount = ScreeningRecord::where('donation_id', $donation->id)->count();
        $this->assertGreaterThan(0, $screeningsCount, 'Mandatory infectious disease screenings should be initialized.');
    }

    public function test_ineligible_donor_is_strictly_rejected(): void
    {
        $ineligibleDonor = Donor::factory()->notEligible()->create([
            'blood_group' => 'O-',
            'full_name' => 'Ineligible Person',
        ]);
        DonorEligibilityRecord::factory()->notEligible()->for($ineligibleDonor)->create();

        $payload = [
            'redirect_to' => 'dashboard',
            'donor_id' => $ineligibleDonor->donor_code,
            'donation_date' => now()->toDateString(),
            'donation_time' => '10:00',
            'quantity_ml' => 450,
            'collection_status' => 'Completed',
            'staff_member' => 'Staff Sarah',
        ];

        $response = $this->actingAs($this->staff)
            ->from(route('dashboard'))
            ->post(route('donations.store'), $payload);

        $response->assertSessionHasErrors('eligibility_status');
        $this->assertSame(0, Donation::where('donor_id', $ineligibleDonor->id)->count());
    }

    public function test_donation_with_mobile_camp_selection(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'A+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();

        $camp = DonationCamp::factory()->create([
            'camp_code' => 'CAMP-TEST-99',
            'name' => 'City Park Mobile Drive',
            'venue_name' => 'City Park Pavilion',
            'status' => 'Ongoing',
            'created_by_user_id' => $this->admin->id,
        ]);

        $payload = [
            'redirect_to' => 'dashboard',
            'donor_id' => $donor->donor_code,
            'donation_date' => now()->toDateString(),
            'camp_id' => $camp->id,
            'quantity_ml' => 450,
            'collection_status' => 'Scheduled',
            'staff_member' => 'Staff Sarah',
            'collection_location' => 'Pavilion Bay 1',
        ];

        $response = $this->actingAs($this->staff)->post(route('donations.store'), $payload);

        $donation = Donation::where('donor_id', $donor->id)->latest()->first();
        $this->assertNotNull($donation);
        $response->assertRedirect(route('dashboard'));
        $this->assertSame($camp->id, $donation->camp_id);
        $this->assertNull($donation->center_id);
        $this->assertSame('Scheduled', $donation->collection_status);
    }

    public function test_persian_localization_in_dashboard_modal(): void
    {
        $donor = Donor::factory()->eligible()->create(['blood_group' => 'AB+']);
        DonorEligibilityRecord::factory()->eligible()->for($donor)->create();

        $response = $this->actingAs($this->admin)
            ->withSession(['locale' => 'fa'])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('ثبت اهدای خون');
        $response->assertSee('انتخاب اهداکننده ثبت‌شده');
        $response->assertSee('مرکز ثابت اهدا');
        $response->assertSee('کمپ سیار اهدا');
        $response->assertSee('فرآورده خونی');
        $response->assertSee('حجم (میلی‌لیتر)');
        $response->assertSee('وضعیت خون‌گیری');
    }

    public function test_donor_and_hospital_roles_cannot_see_modal(): void
    {
        $donorUser = User::factory()->create();
        $donorUser->roles()->attach(Role::where('slug', 'donor')->firstOrFail());

        $response = $this->actingAs($donorUser)->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('record-donation-modal');
    }
}
