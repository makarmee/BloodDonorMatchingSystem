<?php

namespace Tests\Feature;

use App\Models\BloodRequest;
use App\Models\Hospital;
use App\Models\Role;
use App\Models\User;
use App\Support\NumberLocalizer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class PersianTypographyAndNumberLocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_vazirmatn_font_is_loaded_only_when_persian_locale_is_active(): void
    {
        // English (default)
        $enResponse = $this->get(route('home'));
        $enResponse->assertOk();
        $enResponse->assertDontSee('https://fonts.googleapis.com/css2?family=Vazirmatn');

        // Persian (fa)
        $faResponse = $this->withSession(['locale' => 'fa'])->get(route('home'));
        $faResponse->assertOk();
        $faResponse->assertSee('https://fonts.googleapis.com/css2?family=Vazirmatn', false);
        $faResponse->assertSee('fonts.gstatic.com', false);
    }

    public function test_vazirmatn_is_loaded_on_authenticated_pages_in_persian(): void
    {
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $response = $this->actingAs($admin)
            ->withSession(['locale' => 'fa'])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('https://fonts.googleapis.com/css2?family=Vazirmatn', false);
        $response->assertSee('dir="rtl"', false);
    }

    public function test_number_localizer_converts_latin_digits_to_persian_when_locale_is_fa(): void
    {
        app()->setLocale('fa');

        $this->assertSame('۰', NumberLocalizer::toPersianDigits(0));
        $this->assertSame('۱۲۳۴۵۶۷۸۹۰', NumberLocalizer::toPersianDigits('1234567890'));
        $this->assertSame('۲۵ مورد', NumberLocalizer::toPersianDigits('25 مورد'));
        $this->assertSame('۱,۲۵۰', NumberLocalizer::toPersianDigits('1,250'));
    }

    public function test_number_localizer_preserves_latin_digits_when_locale_is_en(): void
    {
        app()->setLocale('en');

        $this->assertSame('0', NumberLocalizer::toPersianDigits(0));
        $this->assertSame('1234567890', NumberLocalizer::toPersianDigits('1234567890'));
        $this->assertSame('25 items', NumberLocalizer::toPersianDigits('25 items'));
    }

    public function test_number_localizer_exempts_codes_blood_groups_emails_and_phones(): void
    {
        app()->setLocale('fa');

        // Reference codes
        $this->assertSame('REQ-2026-0001', NumberLocalizer::toPersianDigits('REQ-2026-0001'));
        $this->assertSame('HSP-042', NumberLocalizer::toPersianDigits('HSP-042'));
        $this->assertSame('DON-1024', NumberLocalizer::toPersianDigits('DON-1024'));
        $this->assertSame('CAMP-5', NumberLocalizer::toPersianDigits('CAMP-5'));

        // Blood groups
        $this->assertSame('A+', NumberLocalizer::toPersianDigits('A+'));
        $this->assertSame('O-', NumberLocalizer::toPersianDigits('O-'));
        $this->assertSame('AB+', NumberLocalizer::toPersianDigits('AB+'));
        $this->assertSame('PRBC', NumberLocalizer::toPersianDigits('PRBC'));

        // Email & Phone
        $this->assertSame('donor@example.com', NumberLocalizer::toPersianDigits('donor@example.com'));
        $this->assertSame('+93 70 123 4567', NumberLocalizer::toPersianDigits('+93 70 123 4567'));
        $this->assertSame('0799-123-456', NumberLocalizer::toPersianDigits('0799-123-456'));
    }

    public function test_blade_num_directive_and_helper_function(): void
    {
        app()->setLocale('fa');

        $renderedNum = Blade::render('@num(42)');
        $this->assertSame('۴۲', trim($renderedNum));

        $renderedDirective = Blade::render('@persianDigits("99")');
        $this->assertSame('۹۹', trim($renderedDirective));

        $this->assertSame('۷', persian_digits(7));

        app()->setLocale('en');

        $renderedEn = Blade::render('@num(42)');
        $this->assertSame('42', trim($renderedEn));
        $this->assertSame('7', persian_digits(7));
    }

    public function test_ui_components_use_logical_alignment_and_rtl_direction(): void
    {
        // Render card component
        $cardHtml = Blade::render('<x-ui.card>Content</x-ui.card>');
        $this->assertStringContainsString('text-start', $cardHtml);

        // Render modal component
        $modalHtml = Blade::render('<x-ui.modal name="test-modal">Modal Body</x-ui.modal>');
        $this->assertStringContainsString('text-start', $modalHtml);

        // Render alert component
        $alertHtml = Blade::render('<x-ui.alert>Notice</x-ui.alert>');
        $this->assertStringContainsString('text-start', $alertHtml);

        // Render stat card in Persian
        app()->setLocale('fa');
        $statCardFa = Blade::render('<x-bdms.stat-card label="Total Donors" :value="150" />');
        $this->assertStringContainsString('text-start', $statCardFa);
        $this->assertStringContainsString('۱۵۰', $statCardFa);

        // Render stat card in English
        app()->setLocale('en');
        $statCardEn = Blade::render('<x-bdms.stat-card label="Total Donors" :value="150" />');
        $this->assertStringContainsString('150', $statCardEn);
    }

    public function test_public_pages_render_persian_numbers_in_persian_mode(): void
    {
        $response = $this->withSession(['locale' => 'fa'])->get(route('home'));
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);

        // Check stock page in Persian
        $stockResponse = $this->withSession(['locale' => 'fa'])->get(route('public.stock'));
        $stockResponse->assertOk();
        $stockResponse->assertSee('dir="rtl"', false);
    }

    public function test_all_priority_labels_translate_properly_in_both_persian_and_english(): void
    {
        // Persian translations
        app()->setLocale('fa');
        $this->assertSame('کم', __('Low'));
        $this->assertSame('عادی', __('Normal'));
        $this->assertSame('بالا', __('High'));
        $this->assertSame('بحرانی', __('Critical'));
        $this->assertSame('فوری', __('Urgent'));
        $this->assertSame('اورژانسی', __('Emergency'));
        $this->assertSame('عادی', __('Routine'));

        // Lowercase variants
        $this->assertSame('کم', __('low'));
        $this->assertSame('عادی', __('normal'));
        $this->assertSame('بالا', __('high'));
        $this->assertSame('بحرانی', __('critical'));
        $this->assertSame('فوری', __('urgent'));
        $this->assertSame('اورژانسی', __('emergency'));
        $this->assertSame('عادی', __('routine'));

        // English original labels preserved
        app()->setLocale('en');
        $this->assertSame('Low', __('Low'));
        $this->assertSame('Normal', __('Normal'));
        $this->assertSame('High', __('High'));
        $this->assertSame('Critical', __('Critical'));
        $this->assertSame('Urgent', __('Urgent'));
        $this->assertSame('Emergency', __('Emergency'));
        $this->assertSame('Routine', __('Routine'));

        $this->assertSame('low', __('low'));
        $this->assertSame('normal', __('normal'));
        $this->assertSame('high', __('high'));
        $this->assertSame('critical', __('critical'));
        $this->assertSame('urgent', __('urgent'));
        $this->assertSame('emergency', __('emergency'));
        $this->assertSame('routine', __('routine'));
    }

    public function test_urgency_badge_renders_correct_translation_for_high_and_other_priorities(): void
    {
        // Persian rendering
        app()->setLocale('fa');

        $highBadgeFa = Blade::render('<x-bdms.urgency-badge level="High" />');
        $this->assertStringContainsString('بالا', $highBadgeFa);

        $lowerHighBadgeFa = Blade::render('<x-bdms.urgency-badge level="high" />');
        $this->assertStringContainsString('بالا', $lowerHighBadgeFa);

        $criticalBadgeFa = Blade::render('<x-bdms.urgency-badge level="critical" />');
        $this->assertStringContainsString('بحرانی', $criticalBadgeFa);

        $normalBadgeFa = Blade::render('<x-bdms.urgency-badge level="normal" />');
        $this->assertStringContainsString('عادی', $normalBadgeFa);

        $routineBadgeFa = Blade::render('<x-bdms.urgency-badge level="routine" />');
        $this->assertStringContainsString('عادی', $routineBadgeFa);

        // English rendering
        app()->setLocale('en');

        $highBadgeEn = Blade::render('<x-bdms.urgency-badge level="High" />');
        $this->assertStringContainsString('High', $highBadgeEn);

        $lowerHighBadgeEn = Blade::render('<x-bdms.urgency-badge level="high" />');
        $this->assertStringContainsString('High', $lowerHighBadgeEn);

        $criticalBadgeEn = Blade::render('<x-bdms.urgency-badge level="critical" />');
        $this->assertStringContainsString('Critical', $criticalBadgeEn);
    }

    public function test_blood_requests_pages_render_high_priority_in_persian_and_english(): void
    {
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->roles()->attach($adminRole);

        $hospital = Hospital::query()->create([
            'hospital_code' => 'HSP-PRIO-01',
            'name' => 'Kabul Central Hospital',
            'type' => 'Teaching Hospital',
            'city' => 'Kabul',
            'address' => 'District 3, Kabul',
            'contact_phone' => '+93 70 000 1111',
            'contact_email' => 'kabul.hospital@example.com',
            'verification_status' => 'Verified',
            'is_active' => true,
        ]);

        $request = BloodRequest::query()->create([
            'request_code' => 'REQ-PRIO-99',
            'hospital_id' => $hospital->id,
            'requested_by_user_id' => $admin->id,
            'contact_person_snapshot' => 'Dr. Hamid',
            'contact_phone_snapshot' => '+93 70 000 2222',
            'contact_email_snapshot' => 'hamid@example.com',
            'department_or_ward' => 'Trauma ICU',
            'blood_group' => 'A+',
            'requested_units' => 5,
            'reserved_units' => 0,
            'fulfilled_units' => 0,
            'priority' => 'High',
            'status' => 'Pending',
            'request_date' => now(),
            'required_date' => now(),
            'request_reason' => 'Emergency surgery transfusion reserve',
        ]);

        // In Persian mode:
        $indexResponseFa = $this->actingAs($admin)
            ->withSession(['locale' => 'fa'])
            ->get(route('requests.index'));

        $indexResponseFa->assertOk();
        $indexResponseFa->assertSee('بالا');
        $indexResponseFa->assertSee('<option value="High">بالا</option>', false);

        $showResponseFa = $this->actingAs($admin)
            ->withSession(['locale' => 'fa'])
            ->get(route('requests.show', $request->id));

        $showResponseFa->assertOk();
        $showResponseFa->assertSee('بالا');

        // In English mode:
        $indexResponseEn = $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('requests.index'));

        $indexResponseEn->assertOk();
        $indexResponseEn->assertSee('High');
        $indexResponseEn->assertSee('<option value="High">High</option>', false);

        $showResponseEn = $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('requests.show', $request->id));

        $showResponseEn->assertOk();
        $showResponseEn->assertSee('High');
    }
}
