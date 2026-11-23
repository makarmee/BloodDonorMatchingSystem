<?php

namespace Tests\Feature;

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
}

