<?php

namespace Tests\Feature;

use App\Models\Donor;
use App\Models\DonorEligibilityRecord;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\BloodCompatibilityRuleSeeder;
use Database\Seeders\BloodComponentSeeder;
use Database\Seeders\ComponentScreeningRequirementSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ScreeningTestTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualPersianTranslationAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hospitalUser;

    private User $donorUser;

    private User $labUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            RolePermissionSeeder::class,
            BloodComponentSeeder::class,
            ScreeningTestTypeSeeder::class,
            ComponentScreeningRequirementSeeder::class,
            BloodCompatibilityRuleSeeder::class,
        ]);

        // Create Admin
        $adminRole = Role::where('slug', 'admin')->firstOrFail();
        $this->admin = User::factory()->create(['name' => 'مدیر سیستم', 'is_active' => true]);
        $this->admin->roles()->attach($adminRole);

        // Create Hospital & Hospital Coordinator
        $hospitalRole = Role::where('slug', 'hospital')->firstOrFail();
        $this->hospitalUser = User::factory()->create(['name' => 'مسئول هماهنگی شفاخانه', 'is_active' => true]);
        $this->hospitalUser->roles()->attach($hospitalRole);
        $hospital = Hospital::create([
            'hospital_code' => 'HSP-TEST-01',
            'name' => 'مرکز انتقال خون عمومی شهر',
            'license_number' => 'MED-LIC-998811',
            'facility_type' => 'General Hospital',
            'contact_phone' => '+1 (555) 019-2834',
            'contact_email' => 'transfusion@citygeneral.invalid',
            'address' => 'بلوار شفاخانه ۱۰۰، بال سی',
            'verification_status' => 'Verified',
            'verified_at' => now(),
            'verified_by_user_id' => $this->admin->id,
            'is_active' => true,
        ]);
        HospitalUser::create([
            'hospital_id' => $hospital->id,
            'user_id' => $this->hospitalUser->id,
            'contact_name' => 'دکتر رابرت کینگ',
            'is_primary_contact' => true,
            'is_active' => true,
        ]);

        // Create Donor & Donor User
        $donorRole = Role::where('slug', 'donor')->firstOrFail();
        $this->donorUser = User::factory()->create(['name' => 'مریم کریمی', 'is_active' => true]);
        $this->donorUser->roles()->attach($donorRole);
        $donor = Donor::create([
            'user_id' => $this->donorUser->id,
            'donor_code' => 'DNR-TEST-01',
            'full_name' => 'مریم کریمی',
            'email' => $this->donorUser->email,
            'phone' => '+1 (555) 443-2211',
            'blood_group' => 'O+',
            'account_status' => 'Active',
            'eligibility_status' => 'Eligible',
            'registered_at' => now()->subMonths(6),
        ]);
        DonorEligibilityRecord::create([
            'donor_id' => $donor->id,
            'eligibility_status' => 'Eligible',
            'reason' => 'Sample record indicates verified administrative and prior clinical clearance for scheduled collection.',
            'clearance_source' => 'Verified intake screening log (Sample Record)',
            'reviewed_at' => now()->subDays(5),
            'reviewed_by_user_id' => $this->admin->id,
            'is_current' => true,
        ]);

        // Create Lab Officer
        $labRole = Role::where('slug', 'lab_officer')->firstOrFail();
        $this->labUser = User::factory()->create(['name' => 'کارشناس لابراتوار', 'is_active' => true]);
        $this->labUser->roles()->attach($labRole);
    }

    private function assertNoEnglishPhrases(string $html, string $pageName): void
    {
        // Remove script, style, svg tags
        $clean = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
        $clean = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $clean);
        $clean = preg_replace('#<svg(.*?)>(.*?)</svg>#is', '', $clean);
        // Remove emails and URLs
        $clean = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '', $clean);
        $clean = preg_replace('#https?://\S+#i', '', $clean);
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$clean);
        libxml_clear_errors();
        $text = $dom->textContent;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $allowedTokens = [
            'BDMS', 'ABO', 'Rh', 'AB', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'A', 'B', 'O',
            'HIV', 'HBV', 'HCV', 'VDRL', 'TPHA', 'COMPAT', 'DNA', 'RNA', 'PRC', 'FFP', 'PLT', 'CRYO',
            'ml', 'kg', 'lbs', 'min', 'mins', 'g', 'dL', 'mg', 'mm', 'Hg', 'UI', 'ID', 'ETA', 'FIFO',
            'HTML', 'CSS', 'JavaScript', 'Alpine', 'Vite', 'Chrome', 'Firefox', 'Safari',
            'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
            'Maryam', 'Karimi', 'City', 'General', 'Transfusion', 'Center',
        ];

        // Match English word sequences of 2 or more words
        preg_match_all('/\b[A-Za-z][A-Za-z0-9\'\-]{2,}(?:\s+[A-Za-z0-9\'\-]+){1,}\b/u', $text, $matches);

        $suspicious = [];
        foreach ($matches[0] as $phrase) {
            $phrase = trim($phrase);
            // Check each word in the phrase
            $words = preg_split('/\s+/', $phrase);
            $unallowed = array_filter($words, function ($w) use ($allowedTokens) {
                if (in_array($w, $allowedTokens, true) || is_numeric($w)) {
                    return false;
                }
                // System entity codes (REQ, DNR, DON, CMP, UNIT, CTR, SCR, HSP)
                if (preg_match('/^(REQ|DNR|DON|CMP|UNIT|CTR|SCR|HSP)-[0-9A-Za-z]+/i', $w)) {
                    return false;
                }

                return true;
            });

            if (empty($unallowed)) {
                continue;
            }
            $suspicious[] = $phrase;
        }

        $suspicious = array_unique($suspicious);
        $this->assertEmpty(
            $suspicious,
            "Page [{$pageName}] contains untranslated English text in Persian mode: ".implode(' | ', $suspicious)
        );
    }

    public function test_public_homepage_renders_clean_persian_with_zero_english(): void
    {
        $response = $this->withSession(['locale' => 'fa'])->get(route('home'));
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $this->assertNoEnglishPhrases($response->getContent(), 'home');
    }

    public function test_public_stock_page_renders_clean_persian_with_zero_english(): void
    {
        $response = $this->withSession(['locale' => 'fa'])->get(route('public.stock'));
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $this->assertNoEnglishPhrases($response->getContent(), 'public.stock');
    }

    public function test_public_camps_directory_renders_clean_persian_with_zero_english(): void
    {
        $response = $this->withSession(['locale' => 'fa'])->get(route('public.camps.index'));
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $this->assertNoEnglishPhrases($response->getContent(), 'public.camps.index');
    }

    public function test_auth_pages_render_clean_persian_with_zero_english(): void
    {
        foreach (['login', 'register', 'password.request'] as $routeName) {
            $response = $this->withSession(['locale' => 'fa'])->get(route($routeName));
            $response->assertOk();
            $response->assertSee('dir="rtl"', false);
            $this->assertNoEnglishPhrases($response->getContent(), $routeName);
        }
    }

    public function test_admin_dashboard_and_operations_render_clean_persian(): void
    {
        $routes = [
            'dashboard',
            'hospitals.index',
            'staff.index',
            'staff.create',
            'inventory.index',
            'inventory.reconcile',
            'donors.index',
            'donors.create',
            'donations.index',
            'donations.create',
            'screening.index',
            'screening.create',
            'requests.index',
            'requests.create',
            'camps.index',
            'camps.create',
        ];

        foreach ($routes as $route) {
            $response = $this->actingAs($this->admin)
                ->withSession(['locale' => 'fa'])
                ->get(route($route));

            $response->assertOk();
            $response->assertSee('dir="rtl"', false);
            $this->assertNoEnglishPhrases($response->getContent(), "admin.{$route}");
        }
    }

    public function test_donor_portal_pages_render_clean_persian(): void
    {
        $donorRoutes = [
            'donor-portal.dashboard',
            'donor-portal.appointments.index',
            'donor-portal.appointments.create',
            'donor-portal.eligibility',
            'donor-portal.history',
        ];

        foreach ($donorRoutes as $route) {
            $response = $this->actingAs($this->donorUser)
                ->withSession(['locale' => 'fa'])
                ->get(route($route));

            $response->assertOk();
            $response->assertSee('dir="rtl"', false);
            $this->assertNoEnglishPhrases($response->getContent(), "donor.{$route}");
        }
    }

    public function test_hospital_coordinator_dashboard_renders_clean_persian(): void
    {
        $response = $this->actingAs($this->hospitalUser)
            ->withSession(['locale' => 'fa'])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $this->assertNoEnglishPhrases($response->getContent(), 'hospital.dashboard');
    }

    public function test_lab_technician_dashboard_renders_clean_persian(): void
    {
        $response = $this->actingAs($this->labUser)
            ->withSession(['locale' => 'fa'])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $this->assertNoEnglishPhrases($response->getContent(), 'lab.dashboard');
    }
}
